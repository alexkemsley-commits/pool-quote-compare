<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PQC_Parser {

	const ALLOWED_EXTS = [ 'pdf', 'docx', 'doc' ];
	const ALLOWED_MIMES = [
		'application/pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'application/msword',
	];

	public static function validate_upload( array $file, $max_bytes ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'pqc_upload', __( 'File upload failed.', 'pool-quote-compare' ) );
		}
		if ( ! empty( $file['error'] ) && $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'pqc_upload', __( 'File upload error.', 'pool-quote-compare' ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > $max_bytes ) {
			return new WP_Error( 'pqc_upload', sprintf( __( 'File %s is too large.', 'pool-quote-compare' ), $file['name'] ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, self::ALLOWED_EXTS, true ) ) {
			return new WP_Error( 'pqc_upload', sprintf( __( 'Unsupported file type: %s. Upload PDF or Word documents only.', 'pool-quote-compare' ), $file['name'] ) );
		}

		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		if ( $finfo ) {
			$mime = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );
			if ( $mime && ! in_array( $mime, self::ALLOWED_MIMES, true ) ) {
				return new WP_Error( 'pqc_upload', sprintf( __( 'Unsupported MIME type for %s.', 'pool-quote-compare' ), $file['name'] ) );
			}
		}
		return true;
	}

	public static function build_document_block( $path, $original_name ) {
		$ext = strtolower( pathinfo( $original_name, PATHINFO_EXTENSION ) );
		if ( $ext === 'pdf' ) {
			$data = @file_get_contents( $path );
			if ( $data === false ) {
				return new WP_Error( 'pqc_read', __( 'Could not read PDF.', 'pool-quote-compare' ) );
			}
			return [
				'type'   => 'document',
				'source' => [
					'type'       => 'base64',
					'media_type' => 'application/pdf',
					'data'       => base64_encode( $data ),
				],
				'title'  => sanitize_file_name( $original_name ),
			];
		}

		if ( $ext === 'docx' ) {
			$text = self::extract_docx_text( $path );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			return [
				'type'   => 'document',
				'source' => [
					'type'       => 'text',
					'media_type' => 'text/plain',
					'data'       => $text,
				],
				'title'  => sanitize_file_name( $original_name ),
			];
		}

		if ( $ext === 'doc' ) {
			$text = self::extract_doc_text( $path );
			if ( is_wp_error( $text ) ) {
				return $text;
			}
			return [
				'type'   => 'document',
				'source' => [
					'type'       => 'text',
					'media_type' => 'text/plain',
					'data'       => $text,
				],
				'title'  => sanitize_file_name( $original_name ),
			];
		}

		return new WP_Error( 'pqc_parser', __( 'Unsupported file type.', 'pool-quote-compare' ) );
	}

	private static function extract_docx_text( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error( 'pqc_zip', __( 'Server is missing the PHP Zip extension required to read DOCX files.', 'pool-quote-compare' ) );
		}
		$zip = new ZipArchive();
		if ( $zip->open( $path ) !== true ) {
			return new WP_Error( 'pqc_zip', __( 'Could not open DOCX archive.', 'pool-quote-compare' ) );
		}
		$parts = [];
		foreach ( [ 'word/document.xml' ] as $entry ) {
			$xml = $zip->getFromName( $entry );
			if ( $xml !== false ) {
				$parts[] = $xml;
			}
		}
		$i = 1;
		while ( ( $xml = $zip->getFromName( "word/header{$i}.xml" ) ) !== false ) {
			$parts[] = $xml;
			$i++;
		}
		$i = 1;
		while ( ( $xml = $zip->getFromName( "word/footer{$i}.xml" ) ) !== false ) {
			$parts[] = $xml;
			$i++;
		}
		$zip->close();

		if ( empty( $parts ) ) {
			return new WP_Error( 'pqc_zip', __( 'DOCX contained no readable text.', 'pool-quote-compare' ) );
		}

		$text = '';
		foreach ( $parts as $xml ) {
			$xml   = str_replace( [ '</w:p>', '<w:br/>', '<w:tab/>' ], [ "\n\n", "\n", "\t" ], $xml );
			$plain = trim( html_entity_decode( strip_tags( $xml ), ENT_QUOTES | ENT_XML1, 'UTF-8' ) );
			if ( $plain !== '' ) {
				$text .= $plain . "\n\n";
			}
		}
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		return trim( $text );
	}

	private static function extract_doc_text( $path ) {
		$raw = @file_get_contents( $path );
		if ( $raw === false ) {
			return new WP_Error( 'pqc_read', __( 'Could not read .doc file.', 'pool-quote-compare' ) );
		}
		$ascii = preg_replace( '/[^\x09\x0A\x0D\x20-\x7E]+/', ' ', $raw );
		$ascii = preg_replace( '/\s{2,}/', ' ', $ascii );
		$ascii = trim( $ascii );
		if ( strlen( $ascii ) < 50 ) {
			return new WP_Error( 'pqc_read', __( 'Could not extract text from legacy .doc file. Please convert it to PDF or DOCX and try again.', 'pool-quote-compare' ) );
		}
		return $ascii;
	}
}
