<?php

namespace App\Http\Controllers;

use App\Models\Procedure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;

/**
 * Multi-format upload endpoint for the procedure "triptych" (PDF + video +
 * infographic). Files land on the `public` disk under storage/app/public/triptych.
 */
class TriptychUploadController extends Controller
{
    /**
     * Disk-relative folder per asset type.
     */
    private const FOLDERS = [
        'pdf_file' => 'triptych/pdf',
        'video_file' => 'triptych/video',
        'infographic_file' => 'triptych/infographic',
    ];

    /**
     * Form field -> procedures column.
     */
    private const COLUMNS = [
        'pdf_file' => 'pdf_path',
        'video_file' => 'video_path',
        'infographic_file' => 'infographic_path',
    ];

    /**
     * Extensions we are willing to write to disk, keyed by validated MIME type.
     * The extension is derived from here rather than from the client-supplied
     * filename, so a "invoice.pdf.php" upload cannot land as an executable name.
     */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * POST /api/v1/procedures/upload-triptych
     *
     * Accepts multipart/form-data with any combination of pdf_file, video_file
     * and infographic_file. When `procedure_id` is supplied the saved paths are
     * persisted onto that procedure; otherwise the paths are only returned so
     * the client can attach them on a later create/update call.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'procedure_id' => ['nullable', 'integer', 'exists:procedures,id'],

            'pdf_file' => [
                'nullable',
                File::types(['pdf'])
                    ->extensions(['pdf'])
                    ->max(20 * 1024), // 20 MB
            ],

            'video_file' => [
                'nullable',
                File::types(['mp4', 'webm'])
                    ->extensions(['mp4', 'webm'])
                    ->max(100 * 1024), // 100 MB
            ],

            'infographic_file' => [
                'nullable',
                File::types(['png', 'jpg', 'jpeg', 'webp'])
                    ->extensions(['png', 'jpg', 'jpeg', 'webp'])
                    ->max(10 * 1024), // 10 MB
            ],
        ], [
            'pdf_file.*' => 'Le fichier PDF doit être un application/pdf de 20 Mo maximum.',
            'video_file.*' => 'La vidéo doit être un MP4 ou WebM de 100 Mo maximum.',
            'infographic_file.*' => "L'infographie doit être un PNG, JPEG ou WebP de 10 Mo maximum.",
        ]);

        if (! $request->hasAny(array_keys(self::FOLDERS))) {
            throw ValidationException::withMessages([
                'pdf_file' => 'Au moins un fichier du triptyque doit être fourni.',
            ]);
        }

        // Resolved through the model, so the RLS policy on `procedures` applies:
        // a procedure from another filiale simply does not exist for this
        // session, and the lookup 404s instead of attaching the asset.
        $procedure = isset($validated['procedure_id'])
            ? Procedure::find($validated['procedure_id'])
            : null;

        if (isset($validated['procedure_id']) && ! $procedure) {
            return response()->json(['message' => 'Procédure introuvable.'], 404);
        }

        $paths = [];

        foreach (self::FOLDERS as $field => $folder) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $paths[self::COLUMNS[$field]] = $this->storeAsset($request->file($field), $folder);
        }

        if ($procedure) {
            // Remove the assets we are replacing, so superseded uploads do not
            // accumulate on disk.
            foreach ($paths as $column => $path) {
                if ($procedure->{$column} && $procedure->{$column} !== $path) {
                    Storage::disk('public')->delete($procedure->{$column});
                }
            }

            $procedure->fill($paths)->save();
        }

        return response()->json([
            'message' => 'Fichiers téléversés avec succès.',
            'paths' => $paths,
            'urls' => array_map(
                fn (string $path): string => Storage::disk('public')->url($path),
                $paths
            ),
            'procedure' => $procedure?->fresh(),
        ], 201);
    }

    /**
     * Write one upload under a random, non-guessable name and return its
     * disk-relative path.
     */
    private function storeAsset(UploadedFile $file, string $folder): string
    {
        // getMimeType() sniffs the file contents; it is not the client-declared
        // Content-Type. Validation already restricted us to the keys below.
        $extension = self::EXTENSIONS[$file->getMimeType()] ?? 'bin';

        return $file->storeAs(
            $folder,
            Str::uuid().'.'.$extension,
            'public'
        );
    }
}
