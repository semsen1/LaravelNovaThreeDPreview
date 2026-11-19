# Nova 3D Preview Field

A Laravel Nova file field with an interactive 3D model viewer. Upload a model, preview it on the resource detail and form pages, and rotate, zoom and pan it with the mouse.

Supported formats: **STL, OBJ, PLY, GLB, glTF, 3MF, FBX**.

## Features

- Interactive WebGL viewer (orbit, zoom, pan, wireframe, auto-rotate, reset view)
- Works with local disks and S3 (presigned temporary URLs or streaming through the application)
- Safety limits for previews: file size, estimated browser memory and triangle count
- Large files are loaded manually with a "Show preview" button
- Upload validation by extension and maximum size
- Fully customizable viewer appearance
- All UI texts are translatable and can be overridden per field

## Requirements

- PHP 8.0 or newer
- Laravel Nova (the field extends `Laravel\Nova\Fields\File`)
- Laravel 11 or newer (the `extensions` validation rule is used)

## Installation
```bash
composer semsen1/three-d-preview-field
```

The field is auto-discovered by Laravel. If you build Nova assets manually, run:
```bash
php artisan nova:publish
```

## Quick start
```php
use Semsen1\ThreeDPreviewField\ThreeDPreviewField;

public function fields(NovaRequest $request): array
{
    return [
        ThreeDPreviewField::make('Model', 'model_path')
            ->disk('s3')
            ->maxUploadSize(50)    // MB
            ->maxPreviewSize(30)   // MB
            ->viewerHeight(480),
    ];
}
```

The field extends `Laravel\Nova\Fields\File`, so the standard `File` methods (`disk()`, `path()`, `storeAs()`, `nullable()`, `rules()` and so on) work as usual. All size arguments are in **megabytes**.

## Storage

### Choosing a disk
```php
ThreeDPreviewField::make('Model')->disk('s3');
```

The disk must be defined in `config/filesystems.php`.

### Disk type

The field detects the disk type from the `driver` key of the disk in `config/filesystems.php` (not from the disk name). The type decides how the file is delivered to the browser and which response headers are used.

If the disk is custom or created dynamically and its driver cannot be detected, an `InvalidArgumentException` is thrown. In that case specify the type manually:
```php
ThreeDPreviewField::make('Model')
    ->disk('custom')
    ->diskType('s3'); // 's3', 'local', 'ftp', 'sftp', ...
```

The value is trimmed and lowercased.

### S3: presigned URLs vs. streaming

For S3 disks the browser downloads the model directly through a presigned temporary URL. This requires a **CORS configuration on the bucket** that allows `GET` requests from your Nova domain.

If you cannot configure CORS, let the application stream the file instead:
```php
->streamViaApp()
```

`streamViaApp()` only affects S3 disks. Other disk types are always served through the application.

The lifetime of the temporary URL is 5 minutes by default (minimum 1 minute):
```php
->temporaryUrlMinutes(10)
```

### Response headers

Headers are defined per disk type and are **merged** with the defaults. Use canonical header names (for example `Cache-Control`), because merging is case-sensitive.
```php
->headers('s3', ['Cache-Control' => 'private, max-age=600'])
->s3Headers(['Content-Disposition' => 'attachment'])
->localHeaders(['Cache-Control' => 'no-store'])
```

Defaults:

| Disk type | Header | Value |
|-----------|--------|-------|
| `local` | `Content-Type` | `application/octet-stream` |
| `local` | `Cache-Control` | `private, max-age=3600` |
| `local` | `Content-Disposition` | `inline` |
| `local` | `X-Content-Type-Options` | `nosniff` |
| `s3` | `Content-Type` | `application/octet-stream` |
| `s3` | `Cache-Control` | `private, max-age=3600` |
| `s3` | `Content-Disposition` | `inline` |

For any other disk type (`ftp`, `sftp`, ...) the `local` defaults are used as a base. Calling `headers('ftp', [...])` merges your headers into the `local` defaults and stores them for `ftp` only.

For S3 presigned URLs, headers are converted to `GetObject` response parameters:

| Header | S3 parameter |
|--------|--------------|
| `Content-Type` | `ResponseContentType` |
| `Cache-Control` | `ResponseCacheControl` |
| `Content-Disposition` | `ResponseContentDisposition` |
| `Content-Encoding` | `ResponseContentEncoding` |
| `Content-Language` | `ResponseContentLanguage` |
| `Expires` | `ResponseExpires` |

Other headers (for example `X-Content-Type-Options`) are ignored for S3 presigned URLs. The mapping is available as the `ThreeDPreviewField::S3_RESPONSE_OPTIONS` constant.

## Upload

### Allowed extensions

By default all supported formats are allowed (`ThreeDPreviewField::SUPPORTED_EXTENSIONS`). Restrict them with `extensions()`:
```php
->extensions(['stl', 'glb'])
```

Extensions are normalized (trimmed, lowercased, leading dots removed, duplicates removed). Passing an unsupported extension throws an `InvalidArgumentException`. The list is used for the `accept` attribute of the file input and for the server-side validation rule.

### Maximum upload size
```php
->maxUploadSize(50);   // 50 MB
->maxUploadSize(null); // no limit (default)
```

## Validation

The field adds the following rules to the standard `File` field rules:

- `file`
- `extensions:<allowed extensions>`
- `max:<KB>`, only if `maxUploadSize()` is set (the value is rounded up to whole kilobytes)

You can add your own rules with the usual `->rules()` method.

## Preview limits

Rendering large models can freeze or crash a browser tab, so the field checks several limits before showing the preview.

| Method | Default | Description |
|--------|---------|-------------|
| `maxPreviewSize(float $mb)` | `20` | Files larger than this are not downloaded or previewed. |
| `loadManuallyAbove(float $mb)` | `5` | Files larger than this are not loaded automatically. The user has to click "Show preview". |
| `maxMemory(float $mb)` | `256` | Budget for the estimated memory used by the preview. |
| `limitByDeviceMemory(float $fraction = 0.1)` | `0.1` | Also limits the budget to a fraction of `navigator.deviceMemory`. Clamped to 0–1, `0` disables the limit. |
| `maxTriangles(int $count)` | `1 500 000` | Models with more triangles are not rendered (minimum 1). |
| `memoryFactor(string $ext, float $factor)` | see below | How many times browser memory exceeds the file size for a format (minimum 0.1). |
| `defaultMemoryFactor(float $factor)` | `8.0` | Factor for formats without a specific value (minimum 0.1). |

Default memory factors:

| Format | Factor |
|--------|--------|
| `stl` | 6 |
| `obj` | 10 |
| `ply` | 8 |
| `glb` | 4 |
| `gltf` | 4 |
| `3mf` | 12 |
| `fbx` | 10 |

Estimated memory = file size × memory factor. If the estimate exceeds the budget, the preview is skipped and a message is shown. The file can still be downloaded.
```php
->maxPreviewSize(50)
->loadManuallyAbove(10)
->maxMemory(512)
->limitByDeviceMemory(0.2)
->maxTriangles(3_000_000)
->memoryFactor('stl', 5)
```

## Viewer options

| Method | Default | Description |
|--------|---------|-------------|
| `viewerHeight(int $px)` | `420` | Viewer height in pixels (minimum 120). |
| `backgroundColor(string $color)` | `#f1f5f9` | Scene background color. |
| `modelColor(string $color)` | `#6b8cff` | Default model color (for formats without their own materials). |
| `showGrid(bool $value = true)` | `true` | Show the floor grid. |
| `showAxes(bool $value = true)` | `false` | Show the XYZ axes helper. |
| `autoRotate(bool $value = true)` | `false` | Rotate the model automatically. |
| `autoRotateSpeed(float $speed)` | `2.0` | Auto-rotation speed. |
| `enableZoom(bool $value = true)` | `true` | Allow zooming. |
| `enablePan(bool $value = true)` | `true` | Allow panning. |
| `enableDamping(bool $value = true)` | `true` | Smooth camera inertia. |
| `wireframe(bool $value = true)` | `false` | Start in wireframe mode. |
| `antialias(bool $value = true)` | `true` | Enable antialiasing. |
| `showToolbar(bool $value = true)` | `true` | Show the toolbar (reset view, wireframe, auto-rotate). |
| `maxPixelRatio(float $ratio)` | `2.0` | Upper limit for the device pixel ratio (minimum 1.0). |
| `cameraFov(float $degrees)` | `45.0` | Camera field of view, clamped to 10–120. |
| `zUpExtensions(array $extensions)` | `['stl', 'ply', '3mf']` | Formats that use the Z axis as "up" (typical for CAD). These models are rotated to stand upright. Extensions are lowercased and leading dots removed. |
```php
ThreeDPreviewField::make('Model')
    ->viewerHeight(520)
    ->backgroundColor('#0f172a')
    ->modelColor('#38bdf8')
    ->showAxes()
    ->autoRotate()
    ->autoRotateSpeed(1.5)
    ->zUpExtensions(['stl', 'ply', '3mf', 'obj']);
```

## Translations

All user-facing strings of the field (dropzone, error messages, toolbar buttons, delete confirmation and so on) are passed to the frontend through the field's `texts` meta. The default values are wrapped in Laravel's `__()` helper, so they are translated automatically according to the current application locale.

### Translating with language files

The English text is used as the translation key. Create or edit a JSON language file in your application, for example `lang/ru.json` (`resources/lang/ru.json` for Laravel 8 and older):
```json
{
    "Drop a 3D model here or click to choose": "Перетащите 3D-модель сюда или нажмите для выбора",
    "Allowed formats: :list": "Допустимые форматы: :list",
    "File type .:ext is not allowed": "Тип файла .:ext не разрешён",
    "File is too large to upload (:size, limit :limit)": "Файл слишком большой для загрузки (:size, лимит :limit)",
    "Preview is not available for :ext files": "Предпросмотр недоступен для файлов .:ext",
    "File is too large for preview (:size, limit :limit)": "Файл слишком большой для предпросмотра (:size, лимит :limit)",
    "Preview needs about :estimate of memory, budget is :budget": "Для предпросмотра нужно около :estimate памяти, доступно :budget",
    "Model is too complex for preview (:count triangles, limit :limit)": "Модель слишком сложная для предпросмотра (:count треугольников, лимит :limit)",
    "Could not read the model file": "Не удалось прочитать файл модели",
    "WebGL is not available in this browser": "WebGL недоступен в этом браузере",
    "Could not load the file": "Не удалось загрузить файл",
    "Show preview (:size)": "Показать предпросмотр (:size)",
    "Loading model...": "Загрузка модели...",
    "Reset view": "Сбросить вид",
    "Wireframe": "Каркас",
    "Auto rotate": "Автовращение",
    "Clear selection": "Очистить выбор",
    "Delete file": "Удалить файл",
    "Download": "Скачать",
    "Triangles": "Треугольники",
    "Are you sure you want to delete this file?": "Вы уверены, что хотите удалить этот файл?",
    "The file was deleted!": "Файл удалён!",
    "Could not delete the file": "Не удалось удалить файл"
}
```

Keys that share the same English text (see "Alias keys" below) are translated together, so one JSON entry covers both.

> **Important:** keep the placeholders (`:list`, `:ext`, `:size`, `:limit`, `:estimate`, `:budget`, `:count`) unchanged in your translations. They are replaced with real values by the frontend.

Default texts are resolved when the field is constructed, so the locale must already be set at that moment. This is normal for Nova resources, because `fields()` is called during the request.

### Overriding texts for a single field

Use `texts()` to change several strings at once:
```php
ThreeDPreviewField::make('Model')
    ->texts([
        'dropzone'      => 'Upload your STL/GLB here',
        'download'      => 'Get the file',
        'confirmDelete' => 'Delete this model permanently?',
    ]);
```

Or `text()` to change one string:
```php
ThreeDPreviewField::make('Model')
    ->text('dropzone', 'Drag &amp; drop a model');
```

Overrides are **merged** with the current texts, so you only need to specify the keys you want to change. Overridden values are used as-is. If your application is multilingual, wrap them in `__()` yourself:
```php
->text('dropzone', __('models.dropzone'))
```

Passing an unknown key throws an `InvalidArgumentException` that lists all allowed keys.

To get the full list of defaults (for example, to generate a language file):
```php
ThreeDPreviewField::defaultTexts();
```

### Available text keys

| Key | Default text | Placeholders |
|-----|--------------|--------------|
| `dropzone` | Drop a 3D model here or click to choose | |
| `allowedFormats` | Allowed formats: :list | `:list` |
| `typeNotAllowed` | File type .:ext is not allowed | `:ext` |
| `tooLargeUpload` | File is too large to upload (:size, limit :limit) | `:size`, `:limit` |
| `previewUnavailable` | Preview is not available for :ext files | `:ext` |
| `tooLargePreview` | File is too large for preview (:size, limit :limit) | `:size`, `:limit` |
| `previewMemory` | Preview needs about :estimate of memory, budget is :budget | `:estimate`, `:budget` |
| `tooComplex` | Model is too complex for preview (:count triangles, limit :limit) | `:count`, `:limit` |
| `readError` | Could not read the model file | |
| `noWebGL` | WebGL is not available in this browser | |
| `loadError` | Could not load the file | |
| `showPreview` | Show preview (:size) | `:size` |
| `loading` | Loading model... | |
| `resetView` | Reset view | |
| `wireframe` | Wireframe | |
| `autoRotate` | Auto rotate | |
| `clearSelection` | Clear selection | |
| `deleteFile` | Delete file | |
| `download` | Download | |
| `triangles` | Triangles | |
| `confirmDelete` | Are you sure you want to delete this file? | |
| `deleted` | The file was deleted! | |
| `deleteError` | Could not delete the file | |

### Alias keys

Seven messages exist under two keys with identical default texts. When you override a message for a field, set **both** keys of the pair so the text changes everywhere:

| Key | Alias |
|-----|-------|
| `previewUnavailable` | `unsupported` |
| `tooLargePreview` | `tooLarge` |
| `previewMemory` | `tooMuchMemory` |
| `tooComplex` | `tooManyTriangles` |
| `readError` | `parseFailed` |
| `noWebGL` | `webgl` |
| `loadError` | `network` |
```php
->texts([
    'noWebGL' => 'Your browser does not support WebGL',
    'webgl'   => 'Your browser does not support WebGL',
])
```

## Frontend payload

The field component is registered as `three-d-preview-field`. All options are sent to the frontend in the `threeD` meta key. Names match the PHP properties, with these differences: `viewerHeight` is sent as `height`, and two extra values are added, `diskType` and `accept` (the value for the file input's `accept` attribute). `memoryFactors` is sent as an object, sizes are in bytes.

## Exceptions

| Situation | Exception |
|-----------|-----------|
| Unsupported extension passed to `extensions()` | `InvalidArgumentException` |
| Unknown key passed to `texts()` or `text()` | `InvalidArgumentException` |
| Disk type cannot be detected from `config/filesystems.php` | `InvalidArgumentException` (call `diskType()`) |

The disk type is resolved when the field is serialized for the frontend, so the last exception appears when the page is rendered, not when the field is created.

## Full example
```php
ThreeDPreviewField::make(__('3D model'), 'model_path')
    ->disk('s3')
    ->streamViaApp(false)
    ->temporaryUrlMinutes(10)
    ->s3Headers(['Cache-Control' => 'private, max-age=600'])
    ->extensions(['stl', 'glb', 'gltf'])
    ->maxUploadSize(100)
    ->maxPreviewSize(40)
    ->loadManuallyAbove(8)
    ->maxMemory(512)
    ->limitByDeviceMemory(0.15)
    ->maxTriangles(2_000_000)
    ->viewerHeight(500)
    ->backgroundColor('#ffffff')
    ->modelColor('#4f46e5')
    ->autoRotate()
    ->text('dropzone', __('Drop your model here'))
    ->nullable();
```

## License

The MIT License (MIT).
