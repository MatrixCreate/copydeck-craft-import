<?php

namespace matrixcreate\copydeckimporter\services;

use yii\base\Component;

/**
 * Converts a Copydeck nodes array to an HTML string for Craft rich text fields.
 *
 * Supported node types:
 *   - heading (level 1–4) → <h1>–<h4>
 *   - paragraph           → <p>
 *   - list                → <ul> or <ol> (based on 'ordered' flag)
 *   - ordered_list        → <ol><li> (legacy alias)
 *   - unordered_list      → <ul><li> (legacy alias)
 *   - faq_items           → <details><summary>…</summary><p>…</p></details>
 *   - table               → <table><thead>/<tbody> with <th>/<td> cells
 *   - ctaButton           → <p><a href="url">label</a></p>
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
            'table'          => $this->_renderTable($node),
            'ctaButton'      => $this->_renderCtaButton($node),
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

    /**
     * Renders a table node as an HTML table.
     *
     * Rows with isHeader = true are rendered in <thead> using <th> cells.
     * All other rows are rendered in <tbody> using <td> cells.
     *
     * @param array $node
     * @return string
     */
    private function _renderTable(array $node): string
    {
        $rows = $node['tableRows'] ?? [];

        if (empty($rows)) {
            return '';
        }

        $headerRows = array_values(array_filter($rows, fn ($r) => !empty($r['isHeader'])));
        $bodyRows   = array_values(array_filter($rows, fn ($r) => empty($r['isHeader'])));

        $thead = '';
        foreach ($headerRows as $row) {
            $cells = '';
            foreach ($row['cells'] ?? [] as $cell) {
                $text   = htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<th>{$text}</th>";
            }
            $thead .= "<tr>{$cells}</tr>";
        }

        $tbody = '';
        foreach ($bodyRows as $row) {
            $cells = '';
            foreach ($row['cells'] ?? [] as $cell) {
                $text   = htmlspecialchars($cell, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $cells .= "<td>{$text}</td>";
            }
            $tbody .= "<tr>{$cells}</tr>";
        }

        $html = '<table>';
        if ($thead !== '') {
            $html .= "<thead>{$thead}</thead>";
        }
        if ($tbody !== '') {
            $html .= "<tbody>{$tbody}</tbody>";
        }
        $html .= '</table>';

        return $html;
    }

    /**
     * Renders a ctaButton node as an anchor link.
     *
     * URL is always empty from Copydeck (set by editors in the CMS after import).
     * Wraps in <p> so it occupies its own line in CKEditor output.
     *
     * @param array $node
     * @return string
     */
    private function _renderCtaButton(array $node): string
    {
        $label = htmlspecialchars($node['label'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $url   = htmlspecialchars($node['url'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        if ($label === '') {
            return '';
        }

        return "<p><a href=\"{$url}\" class=\"btn btn-primary\">{$label}</a></p>";
    }
}
