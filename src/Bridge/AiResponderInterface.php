<?php

declare(strict_types=1);

namespace NChat\Bridge;

/**
 * Optional AI responder for chat bot integration.
 *
 * Implement this interface in your application to enable AI-powered
 * chat bot responses (e.g. via OpenAI, Claude, Gemini).
 */
interface AiResponderInterface
{
	/**
	 * Generate an AI response based on conversation context.
	 *
	 * @param string $conversationContext Formatted conversation history
	 * @return string The AI-generated response
	 */
	public function respond(string $conversationContext): string;


	/**
	 * Get the system prompt for the AI model.
	 */
	public function getSystemPrompt(): string;


	/**
	 * Get the bot display name shown in chat.
	 */
	public function getBotName(): string;
}
