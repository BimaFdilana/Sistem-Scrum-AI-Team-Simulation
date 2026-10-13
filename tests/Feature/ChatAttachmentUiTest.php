<?php

namespace Tests\Feature;

use Tests\TestCase;

class ChatAttachmentUiTest extends TestCase
{
    public function test_attachment_preview_hidden_attribute_is_not_overridden_by_css(): void
    {
        $css = file_get_contents(public_path('css/chat.css'));
        $this->assertIsString($css);
        // Atribut hidden harus benar-benar menyembunyikan preview; tanpa ini
        // display:inline-block menimpa hidden dan preview gambar nempel terus.
        $this->assertMatchesRegularExpression(
            '/\.chat-attach-preview\[hidden\]\s*\{\s*display\s*:\s*none/',
            $css,
            'CSS wajib memuat .chat-attach-preview[hidden]{display:none}.'
        );
    }
}
