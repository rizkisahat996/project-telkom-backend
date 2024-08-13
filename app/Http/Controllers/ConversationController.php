<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use Illuminate\Http\Request;
use LucianoTonet\GroqLaravel\Facades\Groq;
use OpenAI\Laravel\Facades\OpenAI;

class ConversationController extends Controller
{
    private $groq;

    public function __construct()
    {
        $this->groq = new Groq();
    }

    public function index()
    {
        $conversations = Conversation::orderBy('created_at', 'asc')->get();
        return response()->json($conversations);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $userMessage = Conversation::create([
            'message' => $request->message,
            'sender' => 'user',
        ]);

        $botResponse = $this->generateBotResponse($request->message);

        $assistantMessage = Conversation::create([
            'message' => $botResponse,
            'sender' => 'assistant',
        ]);

        return response()->json([$userMessage, $assistantMessage], 201);
    }

    private function generateBotResponse($message)
    {
        try {
            $response = $this->groq->chat()->completions()->create([
                'model' => 'llama3-8b-8192',
                'messages' => [
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            return $response['choices'][0]['message']['content'];
        } catch (\Exception $e) {
            return "I'm sorry, I'm having trouble understanding right now. Could you try rephrasing your question?";
        }
    }
}