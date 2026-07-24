<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AcademyQuizController extends Controller
{
    /**
     * Get weekly active "Pousse-Connaissances" QCM quizzes.
     */
    public function index(Request $request): JsonResponse
    {
        // Mock data structure aligned with Chapter 8 requirements
        $quizzes = [
            [
                'id' => 1,
                'title' => 'Pousse-Connaissances Hebdo - Procédure Caisse V2',
                'duration_minutes' => 3,
                'target_score' => 85,
                'questions_count' => 3,
                'status' => 'Disponible',
            ]
        ];

        return response()->json([
            'success' => true,
            'data' => $quizzes
        ]);
    }

    /**
     * Submit user answers for a QCM attempt and calculate compliance score.
     */
    public function submitAttempt(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'answers' => 'required|array',
            'answers.*.question_id' => 'required|integer',
            'answers.*.selected_option' => 'required|integer',
        ]);

        // Calculate score (Simulated evaluation logic)
        $score = rand(80, 100); 
        $passed = $score >= 85;

        return response()->json([
            'success' => true,
            'message' => $passed ? 'Félicitations! Objectif de validation atteint.' : 'Score insuffisant (<85%). Veuillez réviser la procédure.',
            'data' => [
                'quiz_id' => $id,
                'user_id' => $request->user()->id,
                'score' => $score,
                'target_score' => 85,
                'passed' => $passed,
                'completed_at' => now()->toIso8601String(),
            ]
        ]);
    }
}