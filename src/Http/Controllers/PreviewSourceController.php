<?php

namespace Semsen1\ThreeDPreviewField\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Http\Requests\NovaRequest;
use Semsen1\ThreeDPreviewField\ThreeDPreviewField;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PreviewSourceController
{
    public function source(NovaRequest $request, string $resource, string $resourceId, string $field): JsonResponse
    {
        [$f, $path, $disk] = $this->locate($request, $field);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $payload = ['name' => basename($path), 'extension' => $ext];

        if (! in_array($ext, $f->getExtensions(), true)) {
            return response()->json($payload + ['status' => 'unsupported']);
        }

        $size = (int) $disk->size($path);
        $payload['size'] = $size;

        if ($size > $f->getMaxPreviewSizeBytes()) {
            return response()->json($payload + ['status' => 'too_large']);
        }

        if ($f->usesTemporaryUrl()) {
            $url = $disk->providesTemporaryUrls()
                ? $disk->temporaryUrl($path, now()->addMinutes($f->getTemporaryUrlMinutes()), $f->getS3ResponseOptions())
                : $disk->url($path);
        } else {
            $url = route('three-d-preview.stream', [
                'resource'   => $resource,
                'resourceId' => $resourceId,
                'field'      => $field,
            ], false);
        }

        return response()->json($payload + ['status' => 'ok', 'url' => $url]);
    }

    public function stream(NovaRequest $request, string $resource, string $resourceId, string $field): StreamedResponse
    {
        [$f, $path, $disk] = $this->locate($request, $field);

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        abort_unless(in_array($ext, $f->getExtensions(), true), 415);
        abort_if($disk->size($path) > $f->getMaxPreviewSizeBytes(), 413);

        return $disk->response($path, basename($path), $f->getHeaders(), 'inline');
    }

    private function locate(NovaRequest $request, string $attribute): array
    {
        $resource = $request->findResourceOrFail();
        $resource->authorizeToView($request);

        /** @var ThreeDPreviewField|null $field */
        $field = $resource->availableFields($request)
            ->whereInstanceOf(ThreeDPreviewField::class)
            ->first(fn ($f) => $f->attribute === $attribute && $f->authorizedToSee($request));

        abort_if($field === null, 404);

        $path = $resource->model()->getAttribute($field->attribute);
        abort_if(! is_string($path) || $path === '', 404);

        $disk = Storage::disk($field->getStorageDisk());
        abort_unless($disk->exists($path), 404);

        return [$field, $path, $disk];
    }
}
