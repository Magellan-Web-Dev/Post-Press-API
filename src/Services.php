<?php

declare(strict_types=1);

namespace PostPressAPI;

if (!defined('ABSPATH')) exit;

use PostPressAPI\Http\ApiClient;
use PostPressAPI\Http\RequestBuilder;
use PostPressAPI\Integration\ACF\AcfFields;
use PostPressAPI\Integration\ACF\AcfNormalizer;
use PostPressAPI\Integration\ACF\AcfWriter;
use PostPressAPI\Logging\Logger;
use PostPressAPI\Mapping\CoreFields;
use PostPressAPI\Mapping\DestinationRegistry;
use PostPressAPI\Mapping\MappingEngine;
use PostPressAPI\Mapping\MappingValidator;
use PostPressAPI\Mapping\MetaKeyPolicy;
use PostPressAPI\Mapping\PathResolver;
use PostPressAPI\Mapping\SourcePathDiscovery;
use PostPressAPI\Mapping\Transformer;
use PostPressAPI\Presets\PresetFormParser;
use PostPressAPI\Presets\PresetRepository;
use PostPressAPI\Presets\PresetValidator;
use PostPressAPI\Presets\SecretStore;
use PostPressAPI\Scheduling\ContinuationScheduler;
use PostPressAPI\Scheduling\Scheduler;
use PostPressAPI\Support\Clock;
use PostPressAPI\Support\Redactor;
use PostPressAPI\Sync\IdentityResolver;
use PostPressAPI\Sync\ImportRunner;
use PostPressAPI\Sync\Pagination;
use PostPressAPI\Sync\PostLocator;
use PostPressAPI\Sync\PostWriter;
use PostPressAPI\Sync\PreviewService;
use PostPressAPI\Sync\RecordProcessor;
use PostPressAPI\Sync\ResponseReader;
use PostPressAPI\Sync\RunLock;
use PostPressAPI\Sync\RunRepository;

/**
 * Lazily constructed services shared by the admin screens, REST routes, and cron handlers.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(private readonly Clock $clock = new Clock())
    {
    }

    public function clock(): Clock
    {
        return $this->clock;
    }

    public function paths(): PathResolver
    {
        return $this->get(PathResolver::class, static fn () => new PathResolver());
    }

    public function transformer(): Transformer
    {
        return $this->get(Transformer::class, static fn () => new Transformer());
    }

    public function core_fields(): CoreFields
    {
        return $this->get(CoreFields::class, static fn () => new CoreFields());
    }

    public function acf_fields(): AcfFields
    {
        return $this->get(AcfFields::class, static fn () => new AcfFields());
    }

    public function acf_normalizer(): AcfNormalizer
    {
        return $this->get(AcfNormalizer::class, static fn () => new AcfNormalizer());
    }

    public function acf_writer(): AcfWriter
    {
        return $this->get(AcfWriter::class, static fn () => new AcfWriter());
    }

    public function meta_policy(): MetaKeyPolicy
    {
        return $this->get(MetaKeyPolicy::class, fn () => new MetaKeyPolicy($this->acf_fields()));
    }

    public function destinations(): DestinationRegistry
    {
        return $this->get(DestinationRegistry::class, fn () => new DestinationRegistry($this->core_fields(), $this->meta_policy(), $this->acf_fields()));
    }

    public function engine(): MappingEngine
    {
        return $this->get(MappingEngine::class, fn () => new MappingEngine($this->paths(), $this->transformer(), $this->acf_normalizer()));
    }

    public function mapping_validator(): MappingValidator
    {
        return $this->get(MappingValidator::class, fn () => new MappingValidator($this->paths()));
    }

    public function discovery(): SourcePathDiscovery
    {
        return $this->get(SourcePathDiscovery::class, fn () => new SourcePathDiscovery($this->paths()));
    }

    public function presets(): PresetRepository
    {
        return $this->get(PresetRepository::class, static fn () => new PresetRepository());
    }

    public function secrets(): SecretStore
    {
        return $this->get(SecretStore::class, static fn () => new SecretStore());
    }

    /**
     * Portable preset export/import with local dependency resolution.
     *
     * @return \PostPressAPI\Presets\PresetTransfer
     */
    public function transfer(): \PostPressAPI\Presets\PresetTransfer
    {
        return $this->get(\PostPressAPI\Presets\PresetTransfer::class, fn () => new \PostPressAPI\Presets\PresetTransfer($this));
    }

    public function form_parser(): PresetFormParser
    {
        return $this->get(PresetFormParser::class, static fn () => new PresetFormParser());
    }

    public function preset_validator(): PresetValidator
    {
        return $this->get(PresetValidator::class, fn () => new PresetValidator($this->paths(), $this->mapping_validator(), $this->destinations(), $this->secrets()));
    }

    public function api_client(): ApiClient
    {
        return $this->get(ApiClient::class, fn () => new ApiClient(new RequestBuilder(), $this->clock));
    }

    public function response_reader(): ResponseReader
    {
        return $this->get(ResponseReader::class, fn () => new ResponseReader($this->paths()));
    }

    public function pagination(): Pagination
    {
        return $this->get(Pagination::class, fn () => new Pagination($this->paths()));
    }

    public function post_writer(): PostWriter
    {
        return $this->get(PostWriter::class, fn () => new PostWriter($this->acf_writer()));
    }

    public function processor(): RecordProcessor
    {
        return $this->get(RecordProcessor::class, fn () => new RecordProcessor(
            new IdentityResolver($this->paths()),
            $this->engine(),
            new PostLocator(),
            $this->post_writer(),
            $this->clock
        ));
    }

    public function runs(): RunRepository
    {
        return $this->get(RunRepository::class, fn () => new RunRepository($this->secrets()));
    }

    public function lock(): RunLock
    {
        return $this->get(RunLock::class, fn () => new RunLock($this->clock));
    }

    public function logger(): Logger
    {
        return $this->get(Logger::class, fn () => new Logger($this->clock, function (): array {
            // Every saved credential and credential-like request value is masked in the log.
            $secrets = [];
            foreach ($this->presets()->all() as $preset) {
                $secrets[] = $this->secrets()->get((string) $preset['id'])['value'] ?? null;
                array_push($secrets, ...Redactor::preset_values($preset));
            }
            return $secrets;
        }));
    }

    public function continuations(): ContinuationScheduler
    {
        return $this->get(ContinuationScheduler::class, static fn () => new ContinuationScheduler());
    }

    public function runner(): ImportRunner
    {
        return $this->get(ImportRunner::class, fn () => new ImportRunner(
            $this->presets(),
            $this->secrets(),
            $this->runs(),
            $this->lock(),
            $this->api_client(),
            $this->response_reader(),
            $this->pagination(),
            $this->processor(),
            $this->destinations(),
            $this->preset_validator(),
            $this->continuations(),
            $this->logger(),
            $this->clock
        ));
    }

    public function scheduler(): Scheduler
    {
        return $this->get(Scheduler::class, fn () => new Scheduler($this->presets(), $this->runner(), $this->continuations(), $this->logger()));
    }

    public function preview(): PreviewService
    {
        return $this->get(PreviewService::class, fn () => new PreviewService(
            $this->api_client(),
            $this->response_reader(),
            $this->discovery(),
            $this->processor(),
            $this->destinations(),
            $this->pagination()
        ));
    }

    /**
     * Returns a shared instance, creating it on first use.
     *
     * @template T of object
     *
     * @param class-string<T> $id      Service ID.
     * @param callable(): T   $factory Factory.
     *
     * @return T
     */
    private function get(string $id, callable $factory): object
    {
        return $this->instances[$id] ??= $factory();
    }
}
