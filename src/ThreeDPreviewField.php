<?php

namespace Semsen1\ThreeDPreviewField;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Http\Requests\NovaRequest;

class ThreeDPreviewField extends File
{
    public $component = 'three-d-preview-field';

    public const SUPPORTED_EXTENSIONS = ['stl', 'obj', 'ply', 'glb', 'gltf', '3mf', 'fbx'];

    public const S3_RESPONSE_OPTIONS = [
        'content-type' => 'ResponseContentType',
        'cache-control' => 'ResponseCacheControl',
        'content-disposition' => 'ResponseContentDisposition',
        'content-encoding' => 'ResponseContentEncoding',
        'content-language' => 'ResponseContentLanguage',
        'expires' => 'ResponseExpires',
    ];

    protected ?string $discType = null;
    protected bool $streamViaApp = false;
    protected int $temporaryUrlMinutes = 5;
    protected array $headers = [
        'local' => [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline',
            'X-Content-Type-Options' => 'nosniff',
        ],
        's3' => [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => 'inline',
        ],
    ];


    protected array $extensions = self::SUPPORTED_EXTENSIONS;
    protected ?int $maxUploadSize = null;


    protected int $maxPreviewSize = 20 * 1048576;
    protected int $manualLoadThreshold = 5 * 1048576;
    protected int $maxEstimatedMemory = 256 * 1048576;
    protected float $deviceMemoryFraction = 0.1;
    protected int $maxTriangles = 1_500_000;
    protected float $defaultMemoryFactor = 8.0;
    protected array $memoryFactors = [
        'stl' => 6, 'obj' => 10, 'ply' => 8, 'glb' => 4, 'gltf' => 4, '3mf' => 12, 'fbx' => 10,
    ];

    protected int $viewerHeight = 420;
    protected string $backgroundColor = '#f1f5f9';
    protected string $modelColor = '#6b8cff';
    protected bool $showGrid = true;
    protected bool $showAxes = false;
    protected bool $autoRotate = false;
    protected float $autoRotateSpeed = 2.0;
    protected bool $enableZoom = true;
    protected bool $enablePan = true;
    protected bool $enableDamping = true;
    protected bool $wireframe = false;
    protected bool $antialias = true;
    protected bool $showToolbar = true;
    protected float $maxPixelRatio = 2.0;
    protected float $cameraFov = 45.0;
    protected array $zUpExtensions = ['stl', 'ply', '3mf'];

    public function __construct(...$arguments)
    {
        parent::__construct(...$arguments);

        $this->extensions($this->extensions);
        $this->withMeta([
            'texts' => static::defaultTexts(),
        ]);
    }

    public static function defaultTexts(): array
    {
        return [
            'dropzone' => __('Drop a 3D model here or click to choose'),
            'allowedFormats' => __('Allowed formats: :list'),
            'typeNotAllowed' => __('File type .:ext is not allowed'),
            'tooLargeUpload' => __('File is too large to upload (:size, limit :limit)'),
            'previewUnavailable' => __('Preview is not available for :ext files'),
            'tooLargePreview' => __('File is too large for preview (:size, limit :limit)'),
            'previewMemory' => __('Preview needs about :estimate of memory, budget is :budget'),
            'tooComplex' => __('Model is too complex for preview (:count triangles, limit :limit)'),
            'readError' => __('Could not read the model file'),
            'noWebGL' => __('WebGL is not available in this browser'),
            'loadError' => __('Could not load the file'),
            'showPreview' => __('Show preview (:size)'),
            'loading' => __('Loading model...'),
            'resetView' => __('Reset view'),
            'wireframe' => __('Wireframe'),
            'autoRotate' => __('Auto rotate'),
            'clearSelection' => __('Clear selection'),
            'deleteFile' => __('Delete file'),
            'download' => __('Download'),
            'triangles' => __('Triangles'),
            'confirmDelete' => __('Are you sure you want to delete this file?'),
            'deleted' => __('The file was deleted!'),
            'deleteError' => __('Could not delete the file'),
            'unsupported' => __('Preview is not available for :ext files'),
            'tooLarge' => __('File is too large for preview (:size, limit :limit)'),
            'tooMuchMemory' => __('Preview needs about :estimate of memory, budget is :budget'),
            'tooManyTriangles' => __('Model is too complex for preview (:count triangles, limit :limit)'),
            'parseFailed' => __('Could not read the model file'),
            'webgl' => __('WebGL is not available in this browser'),
            'network' => __('Could not load the file'),
        ];
    }

    public function texts(array $texts): static
    {
        $unknown = array_diff(array_keys($texts), array_keys(static::defaultTexts()));

        if ($unknown) {
            throw new InvalidArgumentException(
                'Unknown ImageSafeArea text keys: ' . implode(', ', $unknown)
                . '. Allowed: ' . implode(', ', array_keys(static::defaultTexts()))
            );
        }

        return $this->withMeta([
            'texts' => array_merge($this->meta['texts'] ?? static::defaultTexts(), $texts),
        ]);
    }

    public function text(string $key, string $value): static
    {
        return $this->texts([$key => $value]);
    }


    public function diskType(string $type): static
    {
        $this->diskType = strtolower(trim($type));

        return $this;
    }

    public function streamViaApp(bool $value = true): static
    {
        $this->streamViaApp = $value;

        return $this;
    }

    public function temporaryUrlMinutes(int $minutes): static
    {
        $this->temporaryUrlMinutes = max(1, $minutes);

        return $this;
    }

    public function headers(string $diskType, array $headers): static
    {
        $diskType = strtolower($diskType);
        $base = $this->headers[$diskType]
            ?? $this->headers[$diskType === 's3' ? 's3' : 'local'];

        $this->headers[$diskType] = array_replace($base, $headers);

        return $this;
    }

    public function s3Headers(array $headers): static
    {
        return $this->headers('s3', $headers);
    }

    public function localHeaders(array $headers): static
    {
        return $this->headers('local', $headers);
    }


    public function extensions(array $extensions): static
    {
        $extensions = array_values(array_unique(array_map(
            fn($e) => ltrim(strtolower(trim((string)$e)), '.'),
            $extensions
        )));

        $unknown = array_diff($extensions, self::SUPPORTED_EXTENSIONS);
        if ($unknown) {
            throw new InvalidArgumentException(
                'Unsupported 3D extensions: ' . implode(', ', $unknown)
                . '. Supported: ' . implode(', ', self::SUPPORTED_EXTENSIONS)
            );
        }

        $this->extensions = $extensions;

        return $this->acceptedTypes(implode(',', array_map(fn($e) => '.' . $e, $extensions)));
    }

    public function maxUploadSize(?float $megabytes): static
    {
        $this->maxUploadSize = $megabytes === null ? null : $this->mb($megabytes);

        return $this;
    }


    public function maxPreviewSize(float $megabytes): static
    {
        $this->maxPreviewSize = $this->mb($megabytes);

        return $this;
    }

    public function loadManuallyAbove(float $megabytes): static
    {
        $this->manualLoadThreshold = $this->mb($megabytes);

        return $this;
    }

    public function maxMemory(float $megabytes): static
    {
        $this->maxEstimatedMemory = $this->mb($megabytes);

        return $this;
    }

    public function limitByDeviceMemory(float $fraction = 0.1): static
    {
        $this->deviceMemoryFraction = max(0.0, min(1.0, $fraction));

        return $this;
    }

    public function maxTriangles(int $triangles): static
    {
        $this->maxTriangles = max(1, $triangles);

        return $this;
    }

    public function memoryFactor(string $extension, float $factor): static
    {
        $this->memoryFactors[ltrim(strtolower($extension), '.')] = max(0.1, $factor);

        return $this;
    }

    public function defaultMemoryFactor(float $factor): static
    {
        $this->defaultMemoryFactor = max(0.1, $factor);

        return $this;
    }


    public function viewerHeight(int $pixels): static
    {
        $this->viewerHeight = max(120, $pixels);

        return $this;
    }

    public function backgroundColor(string $color): static
    {
        $this->backgroundColor = $color;

        return $this;
    }

    public function modelColor(string $color): static
    {
        $this->modelColor = $color;

        return $this;
    }

    public function showGrid(bool $value = true): static
    {
        $this->showGrid = $value;

        return $this;
    }

    public function showAxes(bool $value = true): static
    {
        $this->showAxes = $value;

        return $this;
    }

    public function autoRotate(bool $value = true): static
    {
        $this->autoRotate = $value;

        return $this;
    }

    public function autoRotateSpeed(float $speed): static
    {
        $this->autoRotateSpeed = $speed;

        return $this;
    }

    public function enableZoom(bool $value = true): static
    {
        $this->enableZoom = $value;

        return $this;
    }

    public function enablePan(bool $value = true): static
    {
        $this->enablePan = $value;

        return $this;
    }

    public function enableDamping(bool $value = true): static
    {
        $this->enableDamping = $value;

        return $this;
    }

    public function wireframe(bool $value = true): static
    {
        $this->wireframe = $value;

        return $this;
    }

    public function antialias(bool $value = true): static
    {
        $this->antialias = $value;

        return $this;
    }

    public function showToolbar(bool $value = true): static
    {
        $this->showToolbar = $value;

        return $this;
    }

    public function maxPixelRatio(float $ratio): static
    {
        $this->maxPixelRatio = max(1.0, $ratio);

        return $this;
    }

    public function cameraFov(float $degrees): static
    {
        $this->cameraFov = max(10.0, min(120.0, $degrees));

        return $this;
    }

    public function zUpExtensions(array $extensions): static
    {
        $this->zUpExtensions = array_map(fn($e) => ltrim(strtolower($e), '.'), $extensions);

        return $this;
    }

    public function getDiskType(): string
    {
        if ($this->diskType !== null) {
            return $this->diskType;
        }

        $disk = $this->getStorageDisk();
        $driver = config("filesystems.disks.{$disk}.driver");

        if (!is_string($driver) || $driver === '') {
            throw new InvalidArgumentException(
                "Cannot detect the type of disk [{$disk}] from config/filesystems.php. "
                . "Call ->diskType('s3'|'local'|...) on the ThreeDPreview field."
            );
        }

        return strtolower($driver);
    }

    public function usesTemporaryUrl(): bool
    {
        return $this->getDiskType() === 's3' && !$this->streamViaApp;
    }

    public function getHeaders(): array
    {
        $type = $this->getDiskType();

        return $this->headers[$type] ?? $this->headers[$type === 's3' ? 's3' : 'local'];
    }

    public function getS3ResponseOptions(): array
    {
        $options = [];
        foreach ($this->getHeaders() as $name => $value) {
            $key = self::S3_RESPONSE_OPTIONS[strtolower($name)] ?? null;
            if ($key !== null) {
                $options[$key] = $value;
            }
        }

        return $options;
    }

    public function getTemporaryUrlMinutes(): int
    {
        return $this->temporaryUrlMinutes;
    }

    public function getExtensions(): array
    {
        return $this->extensions;
    }

    public function getMaxPreviewSizeBytes(): int
    {
        return $this->maxPreviewSize;
    }

    public function getRules(NovaRequest $request)
    {
        $rules = parent::getRules($request);

        $extra = ['file', 'extensions:' . implode(',', $this->extensions)];
        if ($this->maxUploadSize !== null) {
            $extra[] = 'max:' . (int)ceil($this->maxUploadSize / 1024); // KB
        }

        $rules[$this->attribute] = array_merge(Arr::wrap($rules[$this->attribute] ?? []), $extra);

        return $rules;
    }

    public function meta()
    {
        return array_merge(parent::meta(), ['threeD' => $this->frontendConfig()]);
    }

    protected function frontendConfig(): array
    {
        return [
            'diskType' => $this->getDiskType(),
            'extensions' => $this->extensions,
            'accept' => implode(',', array_map(fn($e) => '.' . $e, $this->extensions)),
            'maxUploadSize' => $this->maxUploadSize,
            'maxPreviewSize' => $this->maxPreviewSize,
            'manualLoadThreshold' => $this->manualLoadThreshold,
            'maxEstimatedMemory' => $this->maxEstimatedMemory,
            'deviceMemoryFraction' => $this->deviceMemoryFraction,
            'maxTriangles' => $this->maxTriangles,
            'memoryFactors' => (object)$this->memoryFactors,
            'defaultMemoryFactor' => $this->defaultMemoryFactor,
            'height' => $this->viewerHeight,
            'backgroundColor' => $this->backgroundColor,
            'modelColor' => $this->modelColor,
            'showGrid' => $this->showGrid,
            'showAxes' => $this->showAxes,
            'autoRotate' => $this->autoRotate,
            'autoRotateSpeed' => $this->autoRotateSpeed,
            'enableZoom' => $this->enableZoom,
            'enablePan' => $this->enablePan,
            'enableDamping' => $this->enableDamping,
            'wireframe' => $this->wireframe,
            'antialias' => $this->antialias,
            'showToolbar' => $this->showToolbar,
            'maxPixelRatio' => $this->maxPixelRatio,
            'cameraFov' => $this->cameraFov,
            'zUpExtensions' => $this->zUpExtensions,
        ];
    }

    private function mb(float $megabytes): int
    {
        return max(0, (int)round($megabytes * 1048576));
    }
}
