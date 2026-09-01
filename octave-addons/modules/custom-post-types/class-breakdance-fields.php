<?php

/*
BREAKDANCE CUSTOM POST FIELDS
-- Implements scalar, image, gallery, group-child, and repeater Dynamic Data
-- fields.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

/*
BREAKDANCE VALUE RESOLVER
-- Reads a top-level meta value or the active group/repeater child value.
---------------------------------------------------------- */

class Octave_Addons_Breakdance_Value_Resolver {

	/*
	META KEY
	-- Names the key the post actually holds this field under. Values written
	-- before the key dropped its _octave_ prefix are still stored that way, so
	-- the legacy key answers whenever the canonical one has no row.
	---------------------------------------------------------- */

	public static function meta_key( array $field_data, $post_id ): string {

		$post_id = (int) $post_id;
		$keys    = [ (string) $field_data['meta_key'] ];
		$legacy  = (string) ( $field_data['legacy_meta_key'] ?? '' );

		if ( '' !== $legacy && ! in_array( $legacy, $keys, true ) ) {

			$keys[] = $legacy;

		}

		if ( $post_id ) {

			foreach ( $keys as $key ) {

				if ( metadata_exists( 'post', $post_id, $key ) ) {

					return $key;

				}

			}

		}

		return '';

	}

	public static function get( array $field_data ) {

		$post_id = (int) get_the_ID();

		if ( empty( $field_data['parent_type'] ) ) {

			$key = self::meta_key( $field_data, $post_id );

			return '' === $key ? $field_data['default_value'] : get_post_meta( $post_id, $key, true );

		}

		if ( 'group' === $field_data['parent_type'] ) {

			$key   = self::meta_key( $field_data, $post_id );
			$group = '' === $key ? [] : get_post_meta( $post_id, $key, true );
			$group = is_array( $group ) ? $group : [];

			return array_key_exists( $field_data['name'], $group ) ? $group[ $field_data['name'] ] : $field_data['default_value'];

		}

		$loop = \Breakdance\DynamicData\LoopController::getInstance( $field_data['parent_name'] );
		$row  = $loop->get();

		if ( empty( $row ) ) {

			$parent = \Breakdance\DynamicData\DynamicDataController::getInstance()->getField( 'octave_post_repeater_' . $field_data['parent_name'] );

			if ( $parent ) {

				$parent->hasSubFields();
				$row = $loop->get();

			}

		}

		return is_array( $row ) && array_key_exists( $field_data['name'], $row ) ? $row[ $field_data['name'] ] : $field_data['default_value'];

	}

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

		return __( 'Octave', 'octave-addons' );

	}

	public function proOnly() {

		return false;

	}

	public function slug() {

		return 'octave_post_field_' . ( $this->field_data['dynamic_name'] ?? $this->field_data['name'] );

	}

	public function returnTypes() {

		return in_array( $this->field_data['type'], [ 'url', 'file' ], true ) ? [ 'string', 'url' ] : [ 'string' ];

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function handler( $attributes ): \Breakdance\DynamicData\StringData {

		$value = Octave_Addons_Breakdance_Value_Resolver::get( $this->field_data );

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

		return __( 'Octave', 'octave-addons' );

	}

	public function proOnly() {

		return false;

	}

	public function slug() {

		return 'octave_post_field_' . ( $this->field_data['dynamic_name'] ?? $this->field_data['name'] );

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function handler( $attributes ): \Breakdance\DynamicData\ImageData {

		$value = Octave_Addons_Breakdance_Value_Resolver::get( $this->field_data );

		return \Breakdance\DynamicData\ImageData::fromAttachmentId( absint( $value ) );

	}

}

}

if ( class_exists( '\\Breakdance\\DynamicData\\GalleryField' ) ) {

class Octave_Addons_Breakdance_Gallery_Field extends \Breakdance\DynamicData\GalleryField {

	protected array $field_data;

	public function __construct( array $field_data ) {

		$this->field_data = $field_data;

	}

	public function label() {

		return $this->field_data['label'];

	}

	public function category() {

		return __( 'Octave', 'octave-addons' );

	}

	public function proOnly() {

		return false;

	}

	public function slug() {

		return 'octave_post_field_' . ( $this->field_data['dynamic_name'] ?? $this->field_data['name'] );

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function handler( $attributes ): \Breakdance\DynamicData\GalleryData {

		$value   = Octave_Addons_Breakdance_Value_Resolver::get( $this->field_data );
		$gallery = new \Breakdance\DynamicData\GalleryData();

		foreach ( is_array( $value ) ? $value : [] as $attachment_id ) {

			$attachment_id = absint( $attachment_id );

			if ( $attachment_id ) {

				$gallery->images[] = \Breakdance\DynamicData\ImageData::fromAttachmentId( $attachment_id );

			}

		}

		return $gallery;

	}

}

}

if ( class_exists( '\\Breakdance\\DynamicData\\RepeaterField' ) ) {

class Octave_Addons_Breakdance_Repeater_Field extends \Breakdance\DynamicData\RepeaterField {

	protected array $field_data;
	protected $loop;
	protected int $current_index = 0;

	public function __construct( array $field_data ) {

		$this->field_data = $field_data;
		$this->loop       = \Breakdance\DynamicData\LoopController::getInstance( $field_data['name'] );

	}

	public function label() {

		return $this->field_data['label'];

	}

	public function category() {

		return __( 'Octave', 'octave-addons' );

	}

	public function proOnly() {

		return false;

	}

	public function slug() {

		return 'octave_post_repeater_' . $this->field_data['name'];

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function hasSubFields( $post_id = null ) {

		$rows = $this->rows( (int) ( $post_id ?: get_the_ID() ) );
		$row  = $rows[ $this->current_index ] ?? false;

		if ( ! is_array( $row ) ) {

			$this->loop->reset();
			$this->current_index = 0;

			return false;

		}

		$this->loop->set( $row );
		$this->current_index++;

		return true;

	}

	public function setSubFieldIndex( $index ) {

		$rows = $this->rows( (int) get_the_ID() );

		$this->loop->set( $rows[ $index ] ?? [] );
		$this->current_index = (int) $index;

	}

	public function parentField() {

		return false;

	}

	public function handler( $attributes ): \Breakdance\DynamicData\RepeaterData {

		return \Breakdance\DynamicData\RepeaterData::fromArray( $this->rows( (int) get_the_ID() ) );

	}

	/*
	ROWS
	-- Reads the repeater's stored rows through the same key resolution the
	-- scalar fields use, so a legacy prefixed value still drives the loop.
	---------------------------------------------------------- */

	protected function rows( int $post_id ): array {

		$key  = Octave_Addons_Breakdance_Value_Resolver::meta_key( $this->field_data, $post_id );
		$rows = '' === $key ? [] : get_post_meta( $post_id, $key, true );

		return is_array( $rows ) ? array_values( $rows ) : [];

	}

}

}
