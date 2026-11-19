<?php
/**
 * Token-aware CSS compaction for relocated stylesheets.
 *
 * @package ApexPressPerformance
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class ApexPress_CSS {
	// Match whole strings, comments and identifiers before handling whitespace.
	private const TOKENS = <<<'REGEX'
~\G(?:
	(?<comment>/\*[^*]*\*+(?:[^/*][^*]*\*+)*/) |
	(?<string>"(?:\\(?:\r\n|[\s\S])|[^"\\\r\n\f])*"|'(?:\\(?:\r\n|[\s\S])|[^'\\\r\n\f])*') |
	(?<space>[\t\n\f\r ]+) |
	(?<name>[#@]?[-_a-z0-9\x80-\xff]+) |
	(?<other>.)
)~six
REGEX;

	private const URL = <<<'REGEX'
~\G\([\t\n\f\r ]*(?:(?<double>"[^"\\\r\n\f]*")|(?<single>'[^'\\\r\n\f]*')|(?<plain>[^"'()\\\x00-\x20\x7f]*))[\t\n\f\r ]*\)~
REGEX;

	public static function relocate( string $css, string $source_url ): string|false {
		$offset = 0;
		$output = '';
		$import = false;
		$length = strlen( $css );

		while ( $offset < $length ) {
			if ( 1 !== preg_match( self::TOKENS, $css, $token, PREG_UNMATCHED_AS_NULL, $offset ) ) {
				return false;
			}
			$offset += strlen( $token[0] );
			$value = $token[0];

			if ( null !== $token['space'] ) {
				$output .= ' ';
				continue;
			}
			if ( null !== $token['comment'] ) {
				// Relative source maps are tied to the unmodified stylesheet.
				if ( preg_match( '/[#@]\s*source(?:Mapping)?URL\s*=/i', $value ) ) {
					return false;
				}
				$output .= $value;
				continue;
			}
			// An escaped identifier could be an at-rule or URL function. Leave it at its source.
			if ( in_array( $token['other'], array( '\\', '"', "'" ), true ) || ( '/' === $token['other'] && ( $css[ $offset ] ?? '' ) === '*' ) ) {
				return false;
			}

			$name = strtolower( $token['name'] ?? '' );
			if ( null !== $token['string'] && $import ) {
				$value = self::relocate_reference( substr( $value, 1, -1 ), $source_url );
				if ( false === $value ) {
					return false;
				}
				$value = $token[0][0] . $value . $token[0][0];
			}
			if ( '(' === ( $css[ $offset ] ?? '' ) ) {
				if ( in_array( $name, array( 'image-set', '-webkit-image-set', 'image', 'src' ), true ) ) {
					// These functions can contain string URLs and URL modifiers.
					return false;
				}
				if ( 'url' === $name ) {
					if ( 1 !== preg_match( self::URL, $css, $url, PREG_UNMATCHED_AS_NULL, $offset ) ) {
						return false;
					}
					$quoted = $url['double'] ?? $url['single'];
					$reference = null !== $quoted ? substr( $quoted, 1, -1 ) : $url['plain'];
					$absolute = self::relocate_reference( $reference, $source_url );
					if ( false === $absolute ) {
						return false;
					}
					$value .= $reference === $absolute ? $url[0] : '("' . str_replace( '"', '\\"', $absolute ) . '")';
					$offset += strlen( $url[0] );
				}
			}
			if ( '@namespace' === $name ) {
				return false;
			}
			$import = '@import' === $name;
			$output .= $value;
		}
		return $output;
	}

	public static function add_font_display( string $css ): string {
		$offset = 0;
		$start = 0;
		$depth = 0;
		$parentheses = 0;
		$font_face = null;
		$has_display = false;
		$previous = '';
		$output = '';
		$length = strlen( $css );

		while ( $offset < $length ) {
			if ( 1 !== preg_match( self::TOKENS, $css, $token, PREG_UNMATCHED_AS_NULL, $offset ) ) {
				return $css;
			}
			$position = $offset;
			$offset += strlen( $token[0] );
			if ( null !== $token['space'] || null !== $token['comment'] ) {
				continue;
			}
			$value = strtolower( $token[0] );
			if ( in_array( $token['other'], array( '\\', '"', "'" ), true ) || ( '/' === $token['other'] && ( $css[ $offset ] ?? '' ) === '*' ) ) {
				return $css;
			}
			if ( '(' === $value ) {
				++$parentheses;
			} elseif ( ')' === $value ) {
				--$parentheses;
				if ( $parentheses < 0 ) {
					return $css;
				}
			}
			if ( $parentheses > 0 ) {
				$previous = $value;
				continue;
			}
			if ( '{' === $value ) {
				++$depth;
				if ( '@font-face' === $previous ) {
					$font_face = $depth;
					$has_display = false;
				}
			} elseif ( '}' === $value ) {
				if ( $font_face === $depth ) {
					if ( ! $has_display ) {
						$delimiter = in_array( $previous, array( '{', ';' ), true ) ? '' : ';';
						$output .= substr( $css, $start, $position - $start ) . $delimiter . 'font-display:swap;';
						$start = $position;
					}
					$font_face = null;
				}
				--$depth;
				if ( $depth < 0 ) {
					return $css;
				}
			} elseif ( $font_face === $depth && 'font-display' === $value && in_array( $previous, array( '{', ';' ), true ) ) {
				$has_display = true;
			}
			$previous = $value;
		}
		return 0 === $depth && 0 === $parentheses ? $output . substr( $css, $start ) : $css;
	}

	private static function relocate_reference( string $reference, string $source_url ): string|false {
		if ( str_contains( $reference, '\\' ) ) {
			return false;
		}
		if ( str_starts_with( $reference, '#' ) || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $reference ) ) {
			return $reference;
		}
		$source = wp_parse_url( $source_url );
		if ( '' === $reference || empty( $source['host'] ) || empty( $source['scheme'] ) || empty( $source['path'] ) ) {
			return false;
		}
		$origin = $source['scheme'] . '://' . $source['host'] . ( isset( $source['port'] ) ? ':' . $source['port'] : '' );
		if ( str_starts_with( $reference, '//' ) ) {
			return $source['scheme'] . ':' . $reference;
		}
		if ( str_starts_with( $reference, '/' ) ) {
			return $origin . $reference;
		}
		if ( str_starts_with( $reference, '?' ) ) {
			return $origin . $source['path'] . $reference;
		}
		return $origin . substr( $source['path'], 0, strrpos( $source['path'], '/' ) + 1 ) . $reference;
	}
}
