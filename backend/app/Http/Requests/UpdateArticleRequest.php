<?php

namespace App\Http\Requests;

use App\Enums\ArticleCriticite;
use App\Enums\ArticleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateArticleRequest extends FormRequest
{
    /**
     * Three conditions, all required: the create-articles Gate (the same one
     * store() checks — a redacteur/admin whose access was revoked shouldn't
     * keep editing existing drafts either), authorship, and still-draft status
     * — once submitted for validation, changing it here would bypass the
     * review it's waiting on. Enforced here rather than in the controller so a
     * denied request never even reaches validation.
     */
    public function authorize(): bool
    {
        $article = $this->route('article');

        return $article
            && $this->user()->can('create-articles')
            && $article->author_id === $this->user()->id
            && $article->status === ArticleStatus::Draft;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content_summary' => ['sometimes', 'nullable', 'string'],
            'tags_metier' => ['sometimes', 'nullable', 'array'],
            'tags_metier.*' => ['string', 'max:50'],
            'criticite' => ['sometimes', Rule::enum(ArticleCriticite::class)],
        ];
    }

    /**
     * Laravel's default "This action is unauthorized." matches the rest of
     * the app's generic English fallbacks nowhere near as well as the French
     * messages every other controller in this project returns — this keeps
     * that consistent instead of leaking a mismatched-tone 403.
     */
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Cet article ne peut être modifié que par son auteur, et uniquement tant qu\'il est encore en brouillon.',
        ], 403));
    }
}
