<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use LucianoTonet\GroqLaravel\Facades\Groq;

class ConversationController extends Controller
{
    private $groq;

    public function __construct()
    {
        $this->groq = new Groq();
    }

    public function index()
    {
        $conversations = auth()->user()->conversations()->orderBy('created_at', 'desc')->get();
        return response()->json($conversations);
    }

    public function show($id)
    {
        $conversation = Conversation::with('messages')->findOrFail($id);
        return response()->json($conversation);
    }

    public function store(Request $request)
    {
        $request->validate([
            'message' => 'string|nullable',
            'title' => 'string|nullable',
        ]);

        $conversation = Conversation::create([
            'user_id' => auth()->id(),
            'title' => $request->title ?? 'New Conversation',
        ]);

        if ($request->has('message')) {
            return $this->addFirstMessage($conversation, $request->message);
        }

        return response()->json($conversation, 201);
    }

    public function storeMessage(Request $request, $conversationId)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $conversation = Conversation::findOrFail($conversationId);

        if ($conversation->messages()->count() === 0) {
            return $this->addFirstMessage($conversation, $request->message);
        }

        $userMessage = $conversation->messages()->create([
            'content' => $request->message,
            'sender' => 'user',
        ]);

        $botResponse = $this->generateBotResponse($request->message);

        $assistantMessage = $conversation->messages()->create([
            'content' => $botResponse,
            'sender' => 'assistant',
        ]);

        $recommendedQuestions = $this->generateRecommendedQuestions($request->message . "\n" . $botResponse);

        return response()->json([
            'messages' => [$userMessage, $assistantMessage],
            'recommendedQuestions' => $recommendedQuestions
        ], 201);
    }

    private function addFirstMessage($conversation, $message)
    {
        $conversation->update([
            'title' => $this->generateTitle($message),
        ]);

        $userMessage = $conversation->messages()->create([
            'content' => $message,
            'sender' => 'user',
        ]);

        $botResponse = $this->generateBotResponse($message);

        $assistantMessage = $conversation->messages()->create([
            'content' => $botResponse,
            'sender' => 'assistant',
        ]);

        $recommendedQuestions = $this->generateRecommendedQuestions($botResponse);

        return response()->json([
            'conversation' => $conversation,
            'messages' => [$userMessage, $assistantMessage],
            'recommendedQuestions' => $recommendedQuestions
        ], 201);
    }

    private function generateTitle($message)
    {
        try {
            $response = $this->groq->chat()->completions()->create([
                'model' => 'llama3-8b-8192',
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate a short, concise title (max 5 words) for the following question or statement:'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            $title = trim($response['choices'][0]['message']['content']);
            return strlen($title) > 50 ? substr($title, 0, 47) . '...' : $title;
        } catch (\Exception $e) {
            return "New Conversation";
        }
    }

    private function generateRecommendedQuestions($context)
    {
        try {
            $response = $this->groq->chat()->completions()->create([
                'model' => 'llama3-8b-8192',
                'messages' => [
                    ['role' => 'system', 'content' => 'Generate 3 short, relevant follow-up questions based on the given context. The questions should be natural continuations of the conversation.'],
                    ['role' => 'user', 'content' => $context],
                ],
            ]);

            $questions = explode("\n", trim($response['choices'][0]['message']['content']));

            $questions = array_filter($questions, function ($item) {
                return !is_null($item) && trim($item) !== '';
            });

            return array_slice($questions, 0, 4);
        } catch (\Exception $e) {
            return [
                "Can you tell me more about that?",
                "What else would you like to know?",
                "Is there a specific aspect you'd like to discuss?"
            ];
        }
    }

    private function generateBotResponse($message)
    {
        try {
            $response = $this->groq->chat()->completions()->create([
                'model' => 'llama3-8b-8192',
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a friendly and helpful AI assistant. Respond naturally to the user\'s messages.'],
                    ['role' => 'user', 'content' => $message],
                ],
            ]);

            return $response['choices'][0]['message']['content'];
        } catch (\Exception $e) {
            return "I apologize, but I'm having trouble processing your request at the moment. Could you please try again?";
        }
    }
}
