<?php

namespace LaravelOpenTelemetry;

use Composer\InstalledVersions;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Arr;
use Illuminate\Support\Env;
use Illuminate\Support\Str;
use LaravelOpenTelemetry\Support\CarbonClock;
use LaravelOpenTelemetry\Support\OpenTelemetryMonologHandler;
use LaravelOpenTelemetry\Support\PropagatorBuilder;
use LaravelOpenTelemetry\Support\SamplerBuilder;
use OpenTelemetry\API\Common\Time\Clock;
use OpenTelemetry\API\Instrumentation\CachedInstrumentation;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Signals;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Aws\Xray\IdGenerator;
use OpenTelemetry\Aws\Xray\Propagator;
use OpenTelemetry\Context\Propagation\TextMapPropagatorInterface;
use OpenTelemetry\Contrib\Grpc\GrpcTransportFactory;
use OpenTelemetry\Contrib\Otlp\HttpEndpointResolver;
use OpenTelemetry\Contrib\Otlp\LogsExporter;
use OpenTelemetry\Contrib\Otlp\OtlpHttpTransportFactory;
use OpenTelemetry\Contrib\Otlp\OtlpUtil;
use OpenTelemetry\Contrib\Otlp\SpanExporter as OtlpSpanExporter;
use OpenTelemetry\Contrib\Zipkin\Exporter as ZipkinExporter;
use OpenTelemetry\SDK\Common\Attribute\Attributes;
use OpenTelemetry\SDK\Common\Configuration\Variables as OTELVariables;
use OpenTelemetry\SDK\Common\Export\Http\PsrTransportFactory;
use OpenTelemetry\SDK\Common\Export\TransportInterface;
use OpenTelemetry\SDK\Logs\Exporter\ConsoleExporterFactory as LogsConsoleExporterFactory;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporterFactory as LogsInMemoryExporterFactory;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LogRecordExporterInterface;
use OpenTelemetry\SDK\Logs\Processor\BatchLogRecordProcessor;
use OpenTelemetry\SDK\Resource\ResourceInfo;
use OpenTelemetry\SDK\Resource\ResourceInfoFactory;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\ConsoleSpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemorySpanExporterFactory;
use OpenTelemetry\SDK\Trace\SpanExporterInterface;
use OpenTelemetry\SDK\Trace\SpanProcessor\BatchSpanProcessorBuilder;
use OpenTelemetry\SDK\Trace\TracerProvider;
use OpenTelemetry\SemConv\ResourceAttributes;
use OpenTelemetry\SemConv\TraceAttributes;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;

class LaravelOpenTelemetryServiceProvider extends PackageServiceProvider
{
    public function packageBooted(): void
    {
        $this->configureEnvironmentVariables();
        $this->injectConfig();
        $this->init();
        $this->registerInstrumentation();
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-opentelemetry')
            ->hasConfigFile();
    }

    protected function init(): void
    {
        Clock::setDefault(new CarbonClock);

        $resource = ResourceInfoFactory::defaultResource()->merge(
            ResourceInfo::create(Attributes::create([
                ResourceAttributes::SERVICE_NAME => config('opentelemetry.service_name'),
            ]))
        );

        if (config('opentelemetry.propagators') === 'xray') {
            $propagator = new Propagator();
            $idGenerator = new IdGenerator();

            $client = new Client();
            $requestFactory = new HttpFactory();

            switch (config('opentelemetry.xray.detector.cloud_platform')) {
                case 'ec2':
                    $detector = new \OpenTelemetry\Aws\Ec2\Detector($client, $requestFactory);
                    $resource->merge($detector->getResource());
                    break;
                case 'ecs':
                    $detector = new \OpenTelemetry\Aws\Ecs\Detector(new \OpenTelemetry\Aws\Ecs\DataProvider(), $client, $requestFactory);
                    $resource->merge($detector->getResource());
                    break;
                case 'eks':
                    $detector = new \OpenTelemetry\Aws\Eks\Detector(new \OpenTelemetry\Aws\Eks\DataProvider(), $client, $requestFactory);
                    $resource->merge($detector->getResource());
                    break;
                case 'lambda':
                    $detector = new \OpenTelemetry\Aws\Lambda\Detector();
                    $resource->merge($detector->getResource());
                    break;
                default:
            }
        } else {
            $propagator = PropagatorBuilder::new()->build(config('opentelemetry.propagators'));
        }

        /**
         * Traces
         */
        $spanExporter = $this->buildSpanExporter();
        $this->app->bind(SpanExporterInterface::class, fn () => $spanExporter);
        $spanProcessor = (new BatchSpanProcessorBuilder($spanExporter))->build();

        $samplerConfig = config('opentelemetry.traces.sampler', []);
        $sampler = SamplerBuilder::new()->build(
            $samplerConfig['type'] ?? 'always_on',
            $samplerConfig['parent'] ?? true,
            $samplerConfig['args'] ?? []
        );


        $tracerProvider = new TracerProvider($spanProcessor, $sampler, $resource, null, $idGenerator ?? null);

        /**
         * Logs
         */
        $logExporter = $this->buildLogsExporter();
        $this->app->bind(LogRecordExporterInterface::class, fn () => $logExporter);
        $logProcessor = new BatchLogRecordProcessor(
            exporter: $logExporter,
            clock: Clock::getDefault()
        );

        $loggerProvider = LoggerProvider::builder()
            ->setResource($resource)
            ->addLogRecordProcessor($logProcessor)
            ->build();

        Sdk::builder()
            ->setTracerProvider($tracerProvider)
            ->setLoggerProvider($loggerProvider)
            ->setPropagator($propagator)
            ->setAutoShutdown(true)
            ->buildAndRegisterGlobal();

        $instrumentation = new CachedInstrumentation(
            name: 'laravel-opentelemetry',
            version: class_exists(InstalledVersions::class) ? InstalledVersions::getPrettyVersion('zenrows/laravel-opentelemetry') : null,
            schemaUrl: TraceAttributes::SCHEMA_URL,
        );

        $this->app->bind(TextMapPropagatorInterface::class, fn () => $propagator);
        $this->app->bind(TracerInterface::class, fn () => $instrumentation->tracer());
        $this->app->bind(LoggerInterface::class, fn () => $instrumentation->logger());

        $this->app->terminating(function () use ($loggerProvider, $tracerProvider) {
            $tracerProvider->forceFlush();
            $loggerProvider->forceFlush();
        });
    }

    protected function registerInstrumentation(): void
    {
        if (Sdk::isDisabled()) {
            return;
        }

        $this->app->booted(function (Application $app) {
            $app->register(InstrumentationServiceProvider::class);
        });
    }

    private function configureEnvironmentVariables(): void
    {
        $envRepository = Env::getRepository();

        $envRepository->set(OTELVariables::OTEL_SERVICE_NAME, config('opentelemetry.service_name'));

        // Disable debug scopes wrapping
        $envRepository->set('OTEL_PHP_DEBUG_SCOPES_DISABLED', '1');
    }

    protected function buildSpanExporter(): SpanExporterInterface
    {
        $tracesExporter = config('opentelemetry.traces.exporter');
        $tracesExporterConfig = config(sprintf('opentelemetry.exporters.%s', $tracesExporter));
        $tracesExporterDriver = is_array($tracesExporterConfig) ? $tracesExporterConfig['driver'] : $tracesExporter;

        return match ($tracesExporterDriver) {
            'zipkin' => $this->buildZipkinExporter($tracesExporterConfig ?? []),
            'otlp' => new OtlpSpanExporter($this->buildOtlpTransport($tracesExporterConfig ?? [], Signals::TRACE)),
            'console' => (new ConsoleSpanExporterFactory)->create(),
            default => (new InMemorySpanExporterFactory)->create(),
        };
    }

    protected function buildLogsExporter(): LogRecordExporterInterface
    {
        $logsExporter = config('opentelemetry.logs.exporter');
        $logsExporterConfig = config(sprintf('opentelemetry.exporters.%s', $logsExporter));
        $logsExporterDriver = is_array($logsExporterConfig) ? $logsExporterConfig['driver'] : $logsExporter;

        return match ($logsExporterDriver) {
            'otlp' => new LogsExporter($this->buildOtlpTransport($logsExporterConfig ?? [], Signals::LOGS)),
            'console' => (new LogsConsoleExporterFactory)->create(),
            default => (new LogsInMemoryExporterFactory)->create()
        };
    }

    /**
     * @phpstan-param Signals::TRACE|Signals::METRICS|Signals::LOGS $signal
     */
    protected function buildOtlpTransport(array $config, string $signal): TransportInterface
    {
        $protocol = $config['protocol'] ?? null;
        $endpoint = $config['endpoint'] ?? 'http://localhost:4318';

        $maxRetries = $config['max_retries'] ?? 3;
        $timeoutMillis = match ($signal) {
            Signals::TRACE => $config['traces_timeout'] ?? 10000,
            Signals::METRICS => $config['metrics_timeout'] ?? 10000,
            Signals::LOGS => $config['logs_timeout'] ?? 10000,
        };

        return match ($protocol) {
            'grpc' => (new GrpcTransportFactory)->create($endpoint.OtlpUtil::method($signal)),
            'http/json', 'json' => (new OtlpHttpTransportFactory)->create(
                endpoint: (new HttpEndpointResolver)->resolveToString($endpoint, $signal),
                contentType: 'application/json',
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries
            ),
            default => (new OtlpHttpTransportFactory)->create(
                endpoint: (new HttpEndpointResolver)->resolveToString($endpoint, $signal),
                contentType: 'application/x-protobuf',
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries
            ),
        };
    }

    protected function buildZipkinExporter(array $config): ZipkinExporter
    {
        $endpoint = Str::of(Arr::get($config, 'endpoint', ''))->rtrim('/')->append('/api/v2/spans')->toString();
        $maxRetries = $config['max_retries'] ?? 3;
        $timeoutMillis = $config['timeout'] ?? 10000;

        return new ZipkinExporter(
            (new PsrTransportFactory(
                Psr18ClientDiscovery::find(),
                Psr17FactoryDiscovery::findRequestFactory(),
                Psr17FactoryDiscovery::findStreamFactory(),
            ))->create(
                endpoint: $endpoint,
                contentType: 'application/json',
                timeout: $timeoutMillis / 1000,
                maxRetries: $maxRetries,
            ),
        );
    }

    protected function injectConfig(): void
    {
        $this->callAfterResolving(Repository::class, function (Repository $config) {
            if ($config->has('logging.channels.otlp')) {
                return;
            }

            $config->set('logging.channels.otlp', [
                'driver' => 'monolog',
                'handler' => OpenTelemetryMonologHandler::class,
                'level' => 'debug',
            ]);
        });
    }
}
