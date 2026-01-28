<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Link injection using DOMDocument (no regex).
 */
class BRZ_Smart_Linker_Link_Injector {
    private $post_id;
    private $dom;
    private $body;
    private $original_html;

    /**
     * @param int    $post_id
     * @param string $html
     */
    public function __construct( $post_id, $html ) {
        $this->post_id       = (int) $post_id;
        $this->original_html = $html;
        $this->dom           = new DOMDocument();
        libxml_use_internal_errors( true );
        $this->dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();
        $this->body = $this->dom;
    }

    /**
     * @param array $links array of rows (id, keyword, target_url, fingerprint)
     * @return array {changed: bool, content: string}
     */
    public function inject( array $links ) {
        $inserted_any = false;

        foreach ( $links as $link ) {
            $keyword    = isset( $link['keyword'] ) ? $link['keyword'] : '';
            $target_url = isset( $link['target_url'] ) ? $link['target_url'] : '';

            if ( empty( $keyword ) || empty( $target_url ) ) {
                continue;
            }

            // Skip if anchor already exists.
            if ( $this->anchor_exists( $keyword, $target_url ) ) {
                continue;
            }

            $injected = $this->inject_single( $keyword, $target_url );
            $inserted_any = $inserted_any || $injected;
        }

        if ( ! $inserted_any ) {
            return array(
                'changed' => false,
                'content' => $this->original_html,
            );
        }

        $html = $this->dom->saveHTML();
        // Remove xml header added earlier
        $html = preg_replace( '/^<\\?xml.+?\\?>/i', '', $html );

        return array(
            'changed' => true,
            'content' => $html,
        );
    }

    /**
     * Check existing anchors for same keyword+URL.
     */
    private function anchor_exists( $keyword, $target_url ) {
        $anchors = $this->dom->getElementsByTagName( 'a' );
        foreach ( $anchors as $a ) {
            $href = $a->getAttribute( 'href' );
            $text = trim( $a->textContent );
            if ( empty( $href ) ) {
                continue;
            }
            if ( $this->urls_match( $href, $target_url ) && $this->texts_match( $text, $keyword ) ) {
                return true;
            }
        }
        return false;
    }

    private function urls_match( $a, $b ) {
        return trailingslashit( strtolower( trim( $a ) ) ) === trailingslashit( strtolower( trim( $b ) ) );
    }

    private function texts_match( $a, $b ) {
        $lower = function( $text ) {
            $text = trim( $text );
            if ( function_exists( 'mb_strtolower' ) ) {
                return mb_strtolower( $text, 'UTF-8' );
            }
            return strtolower( $text );
        };

        return $lower( $a ) === $lower( $b );
    }

    /**
     * Inject anchor into first text node containing the keyword.
     */
    private function inject_single( $keyword, $target_url ) {
        $xpath = new DOMXPath( $this->dom );
        $text_nodes = $xpath->query( '//text()[normalize-space() != ""]' );
        if ( ! $text_nodes ) {
            return false;
        }

        foreach ( $text_nodes as $text_node ) {
            if ( $this->inside_anchor( $text_node ) ) {
                continue;
            }

            $pos = stripos( $text_node->nodeValue, $keyword );
            if ( false === $pos ) {
                continue;
            }

            // Split text node into [before][keyword][after]
            $full = $text_node->nodeValue;

            $before = substr( $full, 0, $pos );
            $match  = substr( $full, $pos, strlen( $keyword ) );
            $after  = substr( $full, $pos + strlen( $keyword ) );

            $parent = $text_node->parentNode;
            if ( $before !== '' ) {
                $parent->insertBefore( $this->dom->createTextNode( $before ), $text_node );
            }

            $a = $this->dom->createElement( 'a', htmlspecialchars( $match, ENT_QUOTES, 'UTF-8' ) );
            $a->setAttribute( 'href', esc_url( $target_url ) );
            $a->setAttribute( 'data-smart-link', '1' );
            $parent->insertBefore( $a, $text_node );

            if ( $after !== '' ) {
                $parent->insertBefore( $this->dom->createTextNode( $after ), $text_node );
            }

            $parent->removeChild( $text_node );
            return true;
        }

        return false;
    }

    /**
     * Verify text node not already within <a>.
     *
     * @param DOMNode $node
     * @return bool
     */
    private function inside_anchor( DOMNode $node ) {
        while ( $node ) {
            if ( $node->nodeName === 'a' ) {
                return true;
            }
            $node = $node->parentNode;
        }
        return false;
    }
}
