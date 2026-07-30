<?php

namespace App\Http\Requests;

use App\Enums\ArticleFileFormat;
use App\Enums\ArticleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Same restriction as UpdateArticleRequest: create-articles Gate, author, and
 * still-draft, all required. An unrecognised {format} is a routing/input
 * problem rather than a permission one, so it gets its own 422 thrown directly
 * — same pattern as RejectArticleRequest's state check.
 */
class UploadArticleFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $format = ArticleFileFormat::tryFrom((string) $this->route('format'));

        if (! $format) {
            throw new HttpResponseException(response()->json([
                'message' => "Format de fichier inconnu : « {$this->route('format')} ». Valeurs acceptées : pdf, infographie, video.",
            ], 422));
        }

        $article = $this->route('article');

        return $this->user()->can('create-articles')
            && $article->author_id === $this->user()->id
            && $article->status === ArticleStatus::Draft;
    }

    public function rules(): array
    {
        // Guaranteed valid: authorize() runs first and would have already
        // thrown for an unrecognised format before rules() is ever reached.
        $format = ArticleFileFormat::from((string) $this->route('format'));

        return [
            'file' => ['required', 'file', $format->validationRule()],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Cet article ne peut recevoir de fichiers que par son auteur, et uniquement tant qu\'il est encore en brouillon.',
        ], 403));
    }
}
