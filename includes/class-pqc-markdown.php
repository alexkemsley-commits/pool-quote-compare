<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal GFM-flavoured markdown to HTML renderer.
 * Supports: headings, paragraphs, bold, italic, inline code,
 * fenced code, blockquotes, unordered/ordered lists, tables,
 * links, horizontal rules, hard line breaks.
 * Output is escaped and suitable for email HTML bodies.
 */
class PQC_Markdown {

	public static function render( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return '';
		}
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );

		$lines  = explode( "\n", $text );
		$out    = '';
		$i      = 0;
		$n      = count( $lines );
		$code_placeholders = [];

		while ( $i < $n ) {
			$line = $lines[ $i ];

			// Fenced code block.
			if ( preg_match( '/^```\s*([a-zA-Z0-9_-]*)\s*$/', $line, $m ) ) {
				$lang  = $m[1];
				$buf   = [];
				$i++;
				while ( $i < $n && ! preg_match( '/^```\s*$/', $lines[ $i ] ) ) {
					$buf[] = $lines[ $i ];
					$i++;
				}
				$i++;
				$code = esc_html( implode( "\n", $buf ) );
				$attr = $lang ? ' class="language-' . esc_attr( $lang ) . '"' : '';
				$out .= "<pre><code{$attr}>{$code}</code></pre>\n";
				continue;
			}

			// Blank line.
			if ( trim( $line ) === '' ) {
				$i++;
				continue;
			}

			// Horizontal rule.
			if ( preg_match( '/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line ) ) {
				$out .= "<hr/>\n";
				$i++;
				continue;
			}

			// ATX headings.
			if ( preg_match( '/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $m ) ) {
				$level = strlen( $m[1] );
				$out  .= '<h' . $level . '>' . self::inline( $m[2] ) . '</h' . $level . ">\n";
				$i++;
				continue;
			}

			// Table (pipe-delimited, with optional header divider).
			if ( preg_match( '/^\s*\|.*\|\s*$/', $line ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^\s*\|?\s*:?-{2,}:?(\s*\|\s*:?-{2,}:?)*\s*\|?\s*$/', $lines[ $i + 1 ] ) ) {
				$header_cells  = self::split_row( $line );
				$divider_cells = self::split_row( $lines[ $i + 1 ] );
				$aligns        = [];
				foreach ( $divider_cells as $cell ) {
					$c = trim( $cell );
					if ( preg_match( '/^:-+:$/', $c ) ) {
						$aligns[] = 'center';
					} elseif ( preg_match( '/^:-+$/', $c ) ) {
						$aligns[] = 'left';
					} elseif ( preg_match( '/^-+:$/', $c ) ) {
						$aligns[] = 'right';
					} else {
						$aligns[] = '';
					}
				}
				$i    += 2;
				$rows  = [];
				while ( $i < $n && preg_match( '/^\s*\|.*\|\s*$/', $lines[ $i ] ) ) {
					$rows[] = self::split_row( $lines[ $i ] );
					$i++;
				}
				$out .= '<table class="pqc-md-table"><thead><tr>';
				foreach ( $header_cells as $idx => $cell ) {
					$style = ! empty( $aligns[ $idx ] ) ? ' style="text-align:' . $aligns[ $idx ] . '"' : '';
					$out  .= "<th{$style}>" . self::inline( trim( $cell ) ) . '</th>';
				}
				$out .= '</tr></thead><tbody>';
				foreach ( $rows as $row ) {
					$out .= '<tr>';
					foreach ( $row as $idx => $cell ) {
						$style = ! empty( $aligns[ $idx ] ) ? ' style="text-align:' . $aligns[ $idx ] . '"' : '';
						$out  .= "<td{$style}>" . self::inline( trim( $cell ) ) . '</td>';
					}
					$out .= '</tr>';
				}
				$out .= "</tbody></table>\n";
				continue;
			}

			// Blockquote.
			if ( preg_match( '/^>\s?(.*)$/', $line ) ) {
				$buf = [];
				while ( $i < $n && preg_match( '/^>\s?(.*)$/', $lines[ $i ], $m ) ) {
					$buf[] = $m[1];
					$i++;
				}
				$inner = self::render( implode( "\n", $buf ) );
				$out  .= "<blockquote>{$inner}</blockquote>\n";
				continue;
			}

			// Unordered list.
			if ( preg_match( '/^\s*[-*+]\s+(.*)$/', $line ) ) {
				$out .= self::consume_list( $lines, $i, $n, false );
				continue;
			}

			// Ordered list.
			if ( preg_match( '/^\s*\d+\.\s+(.*)$/', $line ) ) {
				$out .= self::consume_list( $lines, $i, $n, true );
				continue;
			}

			// Paragraph (greedy: collect until blank or block-starter).
			$buf = [];
			while ( $i < $n ) {
				$peek = $lines[ $i ];
				if ( trim( $peek ) === '' ) {
					break;
				}
				if ( preg_match( '/^```/', $peek ) ) {
					break;
				}
				if ( preg_match( '/^#{1,6}\s+/', $peek ) ) {
					break;
				}
				if ( preg_match( '/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $peek ) ) {
					break;
				}
				if ( preg_match( '/^\s*[-*+]\s+/', $peek ) || preg_match( '/^\s*\d+\.\s+/', $peek ) ) {
					break;
				}
				if ( preg_match( '/^>\s?/', $peek ) ) {
					break;
				}
				if ( preg_match( '/^\s*\|.*\|\s*$/', $peek ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^\s*\|?\s*:?-{2,}:?.*$/', $lines[ $i + 1 ] ) ) {
					break;
				}
				$buf[] = $peek;
				$i++;
			}
			if ( ! empty( $buf ) ) {
				$para = self::inline( implode( "\n", $buf ) );
				$para = preg_replace( "/\n/", "<br/>\n", $para );
				$out .= "<p>{$para}</p>\n";
			}
		}

		return $out;
	}

	private static function consume_list( &$lines, &$i, $n, $ordered ) {
		$pattern = $ordered ? '/^(\s*)\d+\.\s+(.*)$/' : '/^(\s*)[-*+]\s+(.*)$/';
		$items   = [];
		while ( $i < $n && preg_match( $pattern, $lines[ $i ], $m ) ) {
			$content = $m[2];
			$i++;
			// Fold continuation lines (indented by at least 2 spaces) into this item.
			while ( $i < $n && $lines[ $i ] !== '' && preg_match( '/^\s{2,}(.+)$/', $lines[ $i ], $c ) && ! preg_match( $pattern, $lines[ $i ] ) ) {
				$content .= "\n" . $c[1];
				$i++;
			}
			$items[] = $content;
		}
		$tag  = $ordered ? 'ol' : 'ul';
		$html = "<{$tag}>\n";
		foreach ( $items as $it ) {
			$html .= '<li>' . self::inline( $it ) . "</li>\n";
		}
		$html .= "</{$tag}>\n";
		return $html;
	}

	private static function split_row( $line ) {
		$line = trim( $line );
		$line = preg_replace( '/^\|/', '', $line );
		$line = preg_replace( '/\|$/', '', $line );
		return array_map( 'trim', explode( '|', $line ) );
	}

	/**
	 * Inline-level formatting: code, bold, italic, links, escapes.
	 */
	private static function inline( $text ) {
		// Extract inline code spans first so we don't touch their contents.
		$codes = [];
		$text  = preg_replace_callback( '/`([^`\n]+)`/', function ( $m ) use ( &$codes ) {
			$codes[] = '<code>' . esc_html( $m[1] ) . '</code>';
			return "\x01" . ( count( $codes ) - 1 ) . "\x01";
		}, $text );

		$text = esc_html( $text );

		// Links [text](url).
		$text = preg_replace_callback( '/\[([^\]]+)\]\(([^)\s]+)(?:\s+"([^"]*)")?\)/', function ( $m ) {
			$href  = esc_url( $m[2] );
			$label = $m[1];
			$title = isset( $m[3] ) && $m[3] !== '' ? ' title="' . esc_attr( $m[3] ) . '"' : '';
			return '<a href="' . $href . '"' . $title . ' rel="nofollow noopener">' . $label . '</a>';
		}, $text );

		// Bold **text** or __text__.
		$text = preg_replace( '/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/__(.+?)__/s', '<strong>$1</strong>', $text );

		// Italic *text* or _text_ (avoid clobbering inside words).
		$text = preg_replace( '/(?<!\*)\*(?!\*)([^*\n]+?)\*(?!\*)/', '<em>$1</em>', $text );
		$text = preg_replace( '/(?<![a-zA-Z0-9_])_([^_\n]+?)_(?![a-zA-Z0-9_])/', '<em>$1</em>', $text );

		// Restore code spans.
		$text = preg_replace_callback( '/\x01(\d+)\x01/', function ( $m ) use ( $codes ) {
			$idx = (int) $m[1];
			return isset( $codes[ $idx ] ) ? $codes[ $idx ] : '';
		}, $text );

		return $text;
	}

	/**
	 * Default inline CSS wrapper for email rendering — makes tables and
	 * headings look sensible even in clients that strip <style> blocks.
	 */
	public static function email_style_wrap( $html ) {
		$styles = '<style>'
			. '.pqc-md-table{border-collapse:collapse;width:100%;margin:12px 0;}'
			. '.pqc-md-table th,.pqc-md-table td{border:1px solid #d0d7de;padding:6px 10px;font-size:14px;vertical-align:top;}'
			. '.pqc-md-table th{background:#f6f8fa;text-align:left;}'
			. 'blockquote{border-left:3px solid #d0d7de;margin:12px 0;padding:4px 12px;color:#555;}'
			. 'code{background:#f6f8fa;padding:1px 5px;border-radius:3px;font-family:Menlo,Consolas,monospace;font-size:13px;}'
			. 'pre{background:#f6f8fa;padding:12px;border-radius:4px;overflow:auto;}'
			. 'h1,h2,h3,h4{margin-top:18px;margin-bottom:8px;}'
			. 'ul,ol{padding-left:22px;}'
			. '</style>';
		return $styles . $html;
	}
}
