<?php

/*
BREAKDANCE CUSTOM POST FIELDS
-- Implements reusable Dynamic Data field classes for Octave post meta.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

if ( class_exists( '\\Breakdance\\DynamicData\\StringField' ) ) {

class Octave_Addons_Breakdance_String_Field extends \Breakdance\DynamicData\StringField {

	protected array $field_data;

	public function __construct( array $field_data ) {

		$this->field_data = $field_data;

	}

	public function label() {

		return $this->field_data['label'];

	}

	public function category() {

		return 'Octave';

	}

	public function subcategory() {

		return $this->field_data['subcategory'];

	}

	public function slug() {

		return 'octave_post_field_' . $this->field_data['name'];

	}

	public function returnTypes() {

		return in_array( $this->field_data['type'], [ 'url', 'file' ], true ) ? [ 'string', 'url' ] : [ 'string' ];

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function handler( $attributes ): \Breakdance\DynamicData\StringData {

		$value = get_post_meta( get_the_ID(), $this->field_data['meta_key'], true );

		if ( ! metadata_exists( 'post', get_the_ID(), $this->field_data['meta_key'] ) ) {

			$value = $this->field_data['default_value'];

		}

		if ( 'file' === $this->field_data['type'] ) {

			$value = wp_get_attachment_url( absint( $value ) ) ?: '';

		} elseif ( is_array( $value ) ) {

			$value = implode( ', ', $value );

		}

		return \Breakdance\DynamicData\StringData::fromString( (string) $value );

	}

}

}

if ( class_exists( '\\Breakdance\\DynamicData\\ImageField' ) ) {

class Octave_Addons_Breakdance_Image_Field extends \Breakdance\DynamicData\ImageField {

	protected array $field_data;

	public function __construct( array $field_data ) {

		$this->field_data = $field_data;

	}

	public function label() {

		return $this->field_data['label'];

	}

	public function category() {

		return 'Octave';

	}

	public function subcategory() {

		return $this->field_data['subcategory'];

	}

	public function slug() {

		return 'octave_post_field_' . $this->field_data['name'];

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function handler( $attributes ): \Breakdance\DynamicData\ImageData {

		$value = get_post_meta( get_the_ID(), $this->field_data['meta_key'], true );

		if ( ! metadata_exists( 'post', get_the_ID(), $this->field_data['meta_key'] ) ) {

			$value = $this->field_data['default_value'];

		}

		$attachment_id = absint( $value );

		return \Breakdance\DynamicData\ImageData::fromAttachmentId( $attachment_id );

	}

}

}
