<?php

namespace App\Http\Requests;

use App\Enums\AlertCriticite;
use App\Enums\AlertType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body accepted by POST /v1/articles/{article}/alerts.
 *
 * `status`, `filiale_id`, `article_id`, `reported_by`, `taken_by` and the two
 * timestamps are all absent by design — the controller derives every one of
 * them (a new alert is always `ouverte`, always in the reporter's filiale,
 * always about the route-bound article). Same split as StoreArticleRequest.
 */
class StoreArticleAlertRequest extends FormRequest
{
    /**
     * §7.2 puts this in the hands of "tout collaborateur", so authentication —
     * already enforced by the auth:sanctum route group — is the only bar. The
     * controller still refuses to attach an alert to an article the reporter
     * cannot see; that is a visibility rule, not a permission one.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AlertType::class)],
            'criticite' => ['required', Rule::enum(AlertCriticite::class)],
            // Required, and not just `present`: the description is the entire
            // payload of a signalement — a Process Owner picking up an empty
            // one has nothing to act on.
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required' => 'Décrivez l\'écart constaté.',
            'description.min' => 'La description doit faire au moins 10 caractères.',
        ];
    }
}
