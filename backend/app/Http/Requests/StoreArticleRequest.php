<?php

namespace App\Http\Requests;

use App\Enums\ArticleCriticite;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `status`, `filiale_id`, `author_id`, `data_owner_id` and `slug` are
 * deliberately absent here: the controller sets all of them server-side
 * (every new article starts as a draft owned by whoever is creating it), so
 * none of them are ever client input.
 */
class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Any authenticated user may create a draft for now. Restricting this
        // to redacteur/admin is a one-line addition — see the create-articles
        // Gate in AppServiceProvider — deliberately not wired in here, since
        // this task is scoped to CRUD only.
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content_summary' => ['nullable', 'string'],
            'tags_metier' => ['nullable', 'array'],
            'tags_metier.*' => ['string', 'max:50'],
            'criticite' => ['nullable', Rule::enum(ArticleCriticite::class)],
        ];
    }
}
