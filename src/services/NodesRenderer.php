<?php

namespace matrixcreate\copydeckimporter\services;

use yii\base\Component;

/**
 * Converts a Copydeck nodes array to an HTML string for Craft rich text fields.
 *
 * Supported node types:
 *   - heading (level 1–4) → <h1>–<h4>
 *   - paragraph           → <p>
 *   - ordered_list        → <ol><li>
 *   - unordered_list      → <ul><li>
 *
 * No external dependencies. This service is stateless — all methods are pure.
 *
 * @author Matrix Create <hello@matrixcreate.com>
 * @since 1.0.0
 */
class NodesRenderer extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Renders an array of Copydeck nodes to an HTML string.
     *
     * Returns an empty string for null or empty input — callers should handle
     * empty-string fields as they see fit.
     *
     * @param array|null $nodes
     * @return string
     */
    public function render(?array $nodes): string
    {
        if (empty($nodes)) {
            return '';
        }

        $html = '';

        foreach ($nodes as $node) {
            $html .= $this->_renderNode($node);
        }

        return $html;
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders a single node to HTML.
     *
     * Unknown node types are silently skipped.
     *
     * @param array $node
     * @return string
     */
    private function _renderNode(array $node): string
    {
        $type = $node['type'] ?? '';

        return match ($type) {
            'heading'        => $this->_renderHeading($node),
            'paragraph'      => $this->_renderParagraph($node),
            'list'           => $this->_renderList($node, !empty($node['ordered']) ? 'ol' : 'ul'),
            'ordered_list'   => $this->_renderList($node, 'ol'),
            'unordered_list' => $this->_renderList($node, 'ul'),
            'faq_items'      => $this->_renderFaqItems($node),
            default          => '',
        };
    }

    /**
     * Renders a heading node to <h1>–<h4>.
     *
     * Clamps level to the range 1–4. Defaults to <h2> if level is missing.
     *
     * @param array $node
     * @return string
     */
    private function _renderHeading(array $node): string
    {
        $level = (int)($node['level'] ?? 2);
        $level = max(1, min(4, $level));
        $text  = htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<h{$level}>{$text}</h{$level}>";
    }

    /**
     * Renders a paragraph node to <p>.
     *
     * @param array $node
     * @return string
     */
    private function _renderParagraph(array $node): string
    {
        $text = htmlspecialchars($node['text'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<p>{$text}</p>";
    }

    /**
     * Renders an ordered or unordered list node.
     *
     * @param array  $node
     * @param string $tag  'ol' or 'ul'
     * @return string
     */
    private function _renderList(array $node, string $tag): string
    {
        $items = $node['items'] ?? [];

        if (empty($items)) {
            return '';
        }

        $lis = '';

        foreach ($items as $item) {
            $text = is_string($item) ? $item : ($item['text'] ?? '');
            $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lis .= "<li>{$text}</li>";
        }

        return "<{$tag}>{$lis}</{$tag}>";
    }

    /**
     * Renders a faq_items node as details/summary accordion elements.
     *
     * Each FAQ item becomes a <details><summary>question</summary><p>answer</p></details>.
     * CKEditor's Rich Text field supports details/summary natively.
     *
     * @param array $node
     * @return string
     */
    private function _renderFaqItems(array $node): string
    {
        $items = $node['faqItems'] ?? [];

        if (empty($items)) {
            return '';
        }

        $html = '';

        foreach ($items as $item) {
            $question = htmlspecialchars($item['question'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $answer   = htmlspecialchars($item['answer'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            if ($question !== '' || $answer !== '') {
                $html .= "<details><summary>{$question}</summary><p>{$answer}</p></details>";
            }
        }

        return $html;
    }
}
