<?php

namespace App\Http\Requests;

use App\Enums\ArticleStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Which role may reject depends on where the article currently sits in the
 * workflow — pending_metier is rejectable by whoever validates metier,
 * pending_qualite by whoever validates qualite. That's a state check, not a
 * permission check, so a wrong-state call gets its own 422 with a clear
 * message instead of being folded into the generic 403 authorize() failure.
 */
class RejectArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $article = $this->route('article');

        $requiredGate = match ($article->status) {
            ArticleStatus::PendingMetier => 'validate-metier',
            ArticleStatus::PendingQualite => 'validate-qualite',
            default => null,
        };

        if ($requiredGate === null) {
            throw new HttpResponseException(response()->json([
                'message' => "Cet article est actuellement « {$article->status->label()} » ; seul un article en attente de validation (métier ou qualité) peut être rejeté.",
            ], 422));
        }

        return $this->user()->can($requiredGate);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'Vous n\'avez pas les permissions nécessaires pour rejeter cet article à cette étape.',
        ], 403));
    }
}
