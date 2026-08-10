<?php

/*
BREAKDANCE CUSTOM POST FIELDS
-- Implements scalar, image, group-child, and repeater Dynamic Data fields.
---------------------------------------------------------- */

if ( ! defined( 'ABSPATH' ) ) {

	exit;

}

/*
BREAKDANCE VALUE RESOLVER
-- Reads a top-level meta value or the active group/repeater child value.
---------------------------------------------------------- */

class Octave_Addons_Breakdance_Value_Resolver {

	public static function get( array $field_data ) {

		if ( empty( $field_data['parent_type'] ) ) {

			$value = get_post_meta( get_the_ID(), $field_data['meta_key'], true );

			return metadata_exists( 'post', get_the_ID(), $field_data['meta_key'] ) ? $value : $field_data['default_value'];

		}

		if ( 'group' === $field_data['parent_type'] ) {

			$group = get_post_meta( get_the_ID(), $field_data['meta_key'], true );
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

		return 'Octave';

	}

	public function subcategory() {

		return $this->field_data['subcategory'];

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

		return 'Octave';

	}

	public function subcategory() {

		return $this->field_data['subcategory'];

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

		return 'Octave';

	}

	public function subcategory() {

		return $this->field_data['subcategory'];

	}

	public function slug() {

		return 'octave_post_repeater_' . $this->field_data['name'];

	}

	public function availableForPostType( $post_type ) {

		return in_array( $post_type, $this->field_data['post_types'], true );

	}

	public function hasSubFields( $post_id = null ) {

		$rows = get_post_meta( $post_id ?: get_the_ID(), $this->field_data['meta_key'], true );
		$rows = is_array( $rows ) ? array_values( $rows ) : [];
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

		$rows = get_post_meta( get_the_ID(), $this->field_data['meta_key'], true );
		$rows = is_array( $rows ) ? array_values( $rows ) : [];

		$this->loop->set( $rows[ $index ] ?? [] );
		$this->current_index = (int) $index;

	}

	public function parentField() {

		return false;

	}

	public function handler( $attributes ): \Breakdance\DynamicData\RepeaterData {

		$rows = get_post_meta( get_the_ID(), $this->field_data['meta_key'], true );

		return \Breakdance\DynamicData\RepeaterData::fromArray( is_array( $rows ) ? array_values( $rows ) : [] );

	}

}

}
