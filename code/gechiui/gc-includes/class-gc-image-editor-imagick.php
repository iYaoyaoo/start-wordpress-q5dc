<?php
/**
 * GeChiUI Imagick Image Editor
 *
 * @package GeChiUI
 * @subpackage Image_Editor
 */

/**
 * GeChiUI Image Editor Class for Image Manipulation through Imagick PHP Module
 *
 *
 *
 * @see GC_Image_Editor
 */
class GC_Image_Editor_Imagick extends GC_Image_Editor {
	/**
	 * Imagick object.
	 *
	 * @var Imagick
	 */
	protected $image;

	public function __destruct() {
		if ( $this->image instanceof Imagick ) {
			// We don't need the original in memory anymore.
			$this->image->clear();
			$this->image->destroy();
		}
	}

	/**
	 * Checks to see if current environment supports Imagick.
	 *
	 * We require Imagick 2.2.0 or greater, based on whether the queryFormats()
	 * method can be called statically.
	 *
	 *
	 * @param array $args
	 * @return bool
	 */
	public static function test( $args = array() ) {

		// First, test Imagick's extension and classes.
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick', false ) || ! class_exists( 'ImagickPixel', false ) ) {
			return false;
		}

		if ( version_compare( phpversion( 'imagick' ), '2.2.0', '<' ) ) {
			return false;
		}

		$required_methods = array(
			'clear',
			'destroy',
			'valid',
			'getimage',
			'writeimage',
			'getimageblob',
			'getimagegeometry',
			'getimageformat',
			'setimageformat',
			'setimagecompression',
			'setimagecompressionquality',
			'setimagepage',
			'setoption',
			'scaleimage',
			'cropimage',
			'rotateimage',
			'flipimage',
			'flopimage',
			'readimage',
			'readimageblob',
		);

		// Now, test for deep requirements within Imagick.
		if ( ! defined( 'imagick::COMPRESSION_JPEG' ) ) {
			return false;
		}

		$class_methods = array_map( 'strtolower', get_class_methods( 'Imagick' ) );
		if ( array_diff( $required_methods, $class_methods ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Checks to see if editor supports the mime-type specified.
	 *
	 *
	 * @param string $mime_type
	 * @return bool
	 */
	public static function supports_mime_type( $mime_type ) {
		$imagick_extension = strtoupper( self::get_extension( $mime_type ) );

		if ( ! $imagick_extension ) {
			return false;
		}

		// setIteratorIndex is optional unless mime is an animated format.
		// Here, we just say no if you are missing it and aren't loading a jpeg.
		if ( ! method_exists( 'Imagick', 'setIteratorIndex' ) && 'image/jpeg' !== $mime_type ) {
				return false;
		}

		try {
			// phpcs:ignore GeChiUI.PHP.NoSilencedErrors.Discouraged
			return ( (bool) @Imagick::queryFormats( $imagick_extension ) );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Loads image from $this->file into new Imagick Object.
	 *
	 *
	 * @return true|GC_Error True if loaded; GC_Error on failure.
	 */
	public function load() {
		if ( $this->image instanceof Imagick ) {
			return true;
		}

		if ( ! is_file( $this->file ) && ! gc_is_stream( $this->file ) ) {
			return new GC_Error( 'error_loading_image', __( '文件不存在？' ), $this->file );
		}

		/*
		 * Even though Imagick uses less PHP memory than GD, set higher limit
		 * for users that have low PHP.ini limits.
		 */
		gc_raise_memory_limit( 'image' );

		try {
			$this->image    = new Imagick();
			$file_extension = strtolower( pathinfo( $this->file, PATHINFO_EXTENSION ) );

			if ( 'pdf' === $file_extension ) {
				$pdf_loaded = $this->pdf_load_source();

				if ( is_gc_error( $pdf_loaded ) ) {
					return $pdf_loaded;
				}
			} else {
				if ( gc_is_stream( $this->file ) ) {
					// Due to reports of issues with streams with `Imagick::readImageFile()`, uses `Imagick::readImageBlob()` instead.
					$this->image->readImageBlob( file_get_contents( $this->file ), $this->file );
				} else {
					$this->image->readImage( $this->file );
				}
			}

			if ( ! $this->image->valid() ) {
				return new GC_Error( 'invalid_image', __( '该文件类型不是图片。' ), $this->file );
			}

			// Select the first frame to handle animated images properly.
			if ( is_callable( array( $this->image, 'setIteratorIndex' ) ) ) {
				$this->image->setIteratorIndex( 0 );
			}

			$this->mime_type = $this->get_mime_type( $this->image->getImageFormat() );
		} catch ( Exception $e ) {
			return new GC_Error( 'invalid_image', $e->getMessage(), $this->file );
		}

		$updated_size = $this->update_size();

		if ( is_gc_error( $updated_size ) ) {
			return $updated_size;
		}

		return $this->set_quality();
	}

	/**
	 * Sets Image Compression quality on a 1-100% scale.
	 *
	 *
	 * @param int $quality Compression Quality. Range: [1,100]
	 * @return true|GC_Error True if set successfully; GC_Error on failure.
	 */
	public function set_quality( $quality = null ) {
		$quality_result = parent::set_quality( $quality );
		if ( is_gc_error( $quality_result ) ) {
			return $quality_result;
		} else {
			$quality = $this->get_quality();
		}

		try {
			switch ( $this->mime_type ) {
				case 'image/jpeg':
					$this->image->setImageCompressionQuality( $quality );
					$this->image->setImageCompression( imagick::COMPRESSION_JPEG );
					break;
				case 'image/webp':
					$webp_info = gc_get_webp_info( $this->file );

					if ( 'lossless' === $webp_info['type'] ) {
						// Use WebP lossless settings.
						$this->image->setImageCompressionQuality( 100 );
						$this->image->setOption( 'webp:lossless', 'true' );
					} else {
						$this->image->setImageCompressionQuality( $quality );
					}
					break;
				default:
					$this->image->setImageCompressionQuality( $quality );
			}
		} catch ( Exception $e ) {
			return new GC_Error( 'image_quality_error', $e->getMessage() );
		}
		return true;
	}


	/**
	 * Sets or updates current image size.
	 *
	 *
	 * @param int $width
	 * @param int $height
	 * @return true|GC_Error
	 */
	protected function update_size( $width = null, $height = null ) {
		$size = null;
		if ( ! $width || ! $height ) {
			try {
				$size = $this->image->getImageGeometry();
			} catch ( Exception $e ) {
				return new GC_Error( 'invalid_image', __( '无法读取图片尺寸。' ), $this->file );
			}
		}

		if ( ! $width ) {
			$width = $size['width'];
		}

		if ( ! $height ) {
			$height = $size['height'];
		}

		return parent::update_size( $width, $height );
	}

	/**
	 * Resizes current image.
	 *
	 * At minimum, either a height or width must be provided.
	 * If one of the two is set to null, the resize will
	 * maintain aspect ratio according to the provided dimension.
	 *
	 *
	 * @param int|null $max_w Image width.
	 * @param int|null $max_h Image height.
	 * @param bool     $crop
	 * @return true|GC_Error
	 */
	public function resize( $max_w, $max_h, $crop = false ) {
		if ( ( $this->size['width'] == $max_w ) && ( $this->size['height'] == $max_h ) ) {
			return true;
		}

		$dims = image_resize_dimensions( $this->size['width'], $this->size['height'], $max_w, $max_h, $crop );
		if ( ! $dims ) {
			return new GC_Error( 'error_getting_dimensions', __( '无法计算调整后的图片尺寸' ) );
		}

		list( $dst_x, $dst_y, $src_x, $src_y, $dst_w, $dst_h, $src_w, $src_h ) = $dims;

		if ( $crop ) {
			return $this->crop( $src_x, $src_y, $src_w, $src_h, $dst_w, $dst_h );
		}

		// Execute the resize.
		$thumb_result = $this->thumbnail_image( $dst_w, $dst_h );
		if ( is_gc_error( $thumb_result ) ) {
			return $thumb_result;
		}

		return $this->update_size( $dst_w, $dst_h );
	}

	/**
	 * Efficiently resize the current image
	 *
	 * This is a GeChiUI specific implementation of Imagick::thumbnailImage(),
	 * which resizes an image to given dimensions and removes any associated profiles.
	 *
	 *
	 * @param int    $dst_w       The destination width.
	 * @param int    $dst_h       The destination height.
	 * @param string $filter_name Optional. The Imagick filter to use when resizing. Default 'FILTER_TRIANGLE'.
	 * @param bool   $strip_meta  Optional. Strip all profiles, excluding color profiles, from the image. Default true.
	 * @return void|GC_Error
	 */
	protected function thumbnail_image( $dst_w, $dst_h, $filter_name = 'FILTER_TRIANGLE', $strip_meta = true ) {
		$allowed_filters = array(
			'FILTER_POINT',
			'FILTER_BOX',
			'FILTER_TRIANGLE',
			'FILTER_HERMITE',
			'FILTER_HANNING',
			'FILTER_HAMMING',
			'FILTER_BLACKMAN',
			'FILTER_GAUSSIAN',
			'FILTER_QUADRATIC',
			'FILTER_CUBIC',
			'FILTER_CATROM',
			'FILTER_MITCHELL',
			'FILTER_LANCZOS',
			'FILTER_BESSEL',
			'FILTER_SINC',
		);

		/**
		 * Set the filter value if '$filter_name' name is in the allowed list and the related
		 * Imagick constant is defined or fall back to the default filter.
		 */
		if ( in_array( $filter_name, $allowed_filters, true ) && defined( 'Imagick::' . $filter_name ) ) {
			$filter = constant( 'Imagick::' . $filter_name );
		} else {
			$filter = defined( 'Imagick::FILTER_TRIANGLE' ) ? Imagick::FILTER_TRIANGLE : false;
		}

		/**
		 * Filters whether to strip metadata from images when they're resized.
		 *
		 * This filter only applies when resizing using the Imagick editor since GD
		 * always strips profiles by default.
		 *
		 *
		 * @param bool $strip_meta Whether to strip image metadata during resizing. Default true.
		 */
		if ( apply_filters( 'image_strip_meta', $strip_meta ) ) {
			$this->strip_meta(); // Fail silently if not supported.
		}

		try {
			/*
			 * To be more efficient, resample large images to 5x the destination size before resizing
			 * whenever the output size is less that 1/3 of the original image size (1/3^2 ~= .111),
			 * unless we would be resampling to a scale smaller than 128x128.
			 */
			if ( is_callable( array( $this->image, 'sampleImage' ) ) ) {
				$resize_ratio  = ( $dst_w / $this->size['width'] ) * ( $dst_h / $this->size['height'] );
				$sample_factor = 5;

				if ( $resize_ratio < .111 && ( $dst_w * $sample_factor > 128 && $dst_h * $sample_factor > 128 ) ) {
					$this->image->sampleImage( $dst_w * $sample_factor, $dst_h * $sample_factor );
				}
			}

			/*
			 * Use resizeImage() when it's available and a valid filter value is set.
			 * Otherwise, fall back to the scaleImage() method for resizing, which
			 * results in better image quality over resizeImage() with default filter
			 * settings and retains backward compatibility with pre 4.5 functionality.
			 */
			if ( is_callable( array( $this->image, 'resizeImage' ) ) && $filter ) {
				$this->image->setOption( 'filter:support', '2.0' );
				$this->image->resizeImage( $dst_w, $dst_h, $filter, 1 );
			} else {
				$this->image->scaleImage( $dst_w, $dst_h );
			}

			// Set appropriate quality settings after resizing.
			if ( 'image/jpeg' === $this->mime_type ) {
				if ( is_callable( array( $this->image, 'unsharpMaskImage' ) ) ) {
					$this->image->unsharpMaskImage( 0.25, 0.25, 8, 0.065 );
				}

				$this->image->setOption( 'jpeg:fancy-upsampling', 'off' );
			}

			if ( 'image/png' === $this->mime_type ) {
				$this->image->setOption( 'png:compression-filter', '5' );
				$this->image->setOption( 'png:compression-level', '9' );
				$this->image->setOption( 'png:compression-strategy', '1' );
				$this->image->setOption( 'png:exclude-chunk', 'all' );
			}

			/*
			 * If alpha channel is not defined, set it opaque.
			 *
			 * Note that Imagick::getImageAlphaChannel() is only available if Imagick
			 * has been compiled against ImageMagick version 6.4.0 or newer.
			 */
			if ( is_callable( array( $this->image, 'getImageAlphaChannel' ) )
				&& is_callable( array( $this->image, 'setImageAlphaChannel' ) )
				&& defined( 'Imagick::ALPHACHANNEL_UNDEFINED' )
				&& defined( 'Imagick::ALPHACHANNEL_OPAQUE' )
			) {
				if ( $this->image->getImageAlphaChannel() === Imagick::ALPHACHANNEL_UNDEFINED ) {
					$this->image->setImageAlphaChannel( Imagick::ALPHACHANNEL_OPAQUE );
				}
			}

			// Limit the bit depth of resized images to 8 bits per channel.
			if ( is_callable( array( $this->image, 'getImageDepth' ) ) && is_callable( array( $this->image, 'setImageDepth' ) ) ) {
				if ( 8 < $this->image->getImageDepth() ) {
					$this->image->setImageDepth( 8 );
				}
			}

			if ( is_callable( array( $this->image, 'setInterlaceScheme' ) ) && defined( 'Imagick::INTERLACE_NO' ) ) {
				$this->image->setInterlaceScheme( Imagick::INTERLACE_NO );
			}
		} catch ( Exception $e ) {
			return new GC_Error( 'image_resize_error', $e->getMessage() );
		}
	}

	/**
	 * Create multiple smaller images from a single source.
	 *
	 * Attempts to create all sub-sizes and returns the meta data at the end. This
	 * may result in the server running out of resources. When it fails there may be few
	 * "orphaned" images left over as the meta data is never returned and saved.
	 *
	 * As of 5.3.0 the preferred way to do this is with `make_subsize()`. It creates
	 * the new images one at a time and allows for the meta data to be saved after
	 * each new image is created.
	 *
	 *
	 * @param array $sizes {
	 *     An array of image size data arrays.
	 *
	 *     Either a height or width must be provided.
	 *     If one of the two is set to null, the resize will
	 *     maintain aspect ratio according to the provided dimension.
	 *
	 *     @type array $size {
	 *         Array of height, width values, and whether to crop.
	 *
	 *         @type int  $width  Image width. Optional if `$height` is specified.
	 *         @type int  $height Image height. Optional if `$width` is specified.
	 *         @type bool $crop   Optional. Whether to crop the image. Default false.
	 *     }
	 * }
	 * @return array An array of resized images' metadata by size.
	 */
	public function multi_resize( $sizes ) {
		$metadata = array();

		foreach ( $sizes as $size => $size_data ) {
			$meta = $this->make_subsize( $size_data );

			if ( ! is_gc_error( $meta ) ) {
				$metadata[ $size ] = $meta;
			}
		}

		return $metadata;
	}

	/**
	 * Create an image sub-size and return the image meta data value for it.
	 *
	 *
	 * @param array $size_data {
	 *     Array of size data.
	 *
	 *     @type int  $width  The maximum width in pixels.
	 *     @type int  $height The maximum height in pixels.
	 *     @type bool $crop   Whether to crop the image to exact dimensions.
	 * }
	 * @return array|GC_Error The image data array for inclusion in the `sizes` array in the image meta,
	 *                        GC_Error object on error.
	 */
	public function make_subsize( $size_data ) {
		if ( ! isset( $size_data['width'] ) && ! isset( $size_data['height'] ) ) {
			return new GC_Error( 'image_subsize_create_error', __( '未能缩放此图片，宽和高均未被设置。' ) );
		}

		$orig_size  = $this->size;
		$orig_image = $this->image->getImage();

		if ( ! isset( $size_data['width'] ) ) {
			$size_data['width'] = null;
		}

		if ( ! isset( $size_data['height'] ) ) {
			$size_data['height'] = null;
		}

		if ( ! isset( $size_data['crop'] ) ) {
			$size_data['crop'] = false;
		}

		$resized = $this->resize( $size_data['width'], $size_data['height'], $size_data['crop'] );

		if ( is_gc_error( $resized ) ) {
			$saved = $resized;
		} else {
			$saved = $this->_save( $this->image );

			$this->image->clear();
			$this->image->destroy();
			$this->image = null;
		}

		$this->size  = $orig_size;
		$this->image = $orig_image;

		if ( ! is_gc_error( $saved ) ) {
			unset( $saved['path'] );
		}

		return $saved;
	}

	/**
	 * Crops Image.
	 *
	 *
	 * @param int  $src_x   The start x position to crop from.
	 * @param int  $src_y   The start y position to crop from.
	 * @param int  $src_w   The width to crop.
	 * @param int  $src_h   The height to crop.
	 * @param int  $dst_w   Optional. The destination width.
	 * @param int  $dst_h   Optional. The destination height.
	 * @param bool $src_abs Optional. If the source crop points are absolute.
	 * @return true|GC_Error
	 */
	public function crop( $src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false ) {
		if ( $src_abs ) {
			$src_w -= $src_x;
			$src_h -= $src_y;
		}

		try {
			$this->image->cropImage( $src_w, $src_h, $src_x, $src_y );
			$this->image->setImagePage( $src_w, $src_h, 0, 0 );

			if ( $dst_w || $dst_h ) {
				// If destination width/height isn't specified,
				// use same as width/height from source.
				if ( ! $dst_w ) {
					$dst_w = $src_w;
				}
				if ( ! $dst_h ) {
					$dst_h = $src_h;
				}

				$thumb_result = $this->thumbnail_image( $dst_w, $dst_h );
				if ( is_gc_error( $thumb_result ) ) {
					return $thumb_result;
				}

				return $this->update_size();
			}
		} catch ( Exception $e ) {
			return new GC_Error( 'image_crop_error', $e->getMessage() );
		}

		return $this->update_size();
	}

	/**
	 * Rotates current image counter-clockwise by $angle.
	 *
	 *
	 * @param float $angle
	 * @return true|GC_Error
	 */
	public function rotate( $angle ) {
		/**
		 * $angle is 360-$angle because Imagick rotates clockwise
		 * (GD rotates counter-clockwise)
		 */
		try {
			$this->image->rotateImage( new ImagickPixel( 'none' ), 360 - $angle );

			// Normalize EXIF orientation data so that display is consistent across devices.
			if ( is_callable( array( $this->image, 'setImageOrientation' ) ) && defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
				$this->image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			}

			// Since this changes the dimensions of the image, update the size.
			$result = $this->update_size();
			if ( is_gc_error( $result ) ) {
				return $result;
			}

			$this->image->setImagePage( $this->size['width'], $this->size['height'], 0, 0 );
		} catch ( Exception $e ) {
			return new GC_Error( 'image_rotate_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Flips current image.
	 *
	 *
	 * @param bool $horz Flip along Horizontal Axis
	 * @param bool $vert Flip along Vertical Axis
	 * @return true|GC_Error
	 */
	public function flip( $horz, $vert ) {
		try {
			if ( $horz ) {
				$this->image->flipImage();
			}

			if ( $vert ) {
				$this->image->flopImage();
			}

			// Normalize EXIF orientation data so that display is consistent across devices.
			if ( is_callable( array( $this->image, 'setImageOrientation' ) ) && defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
				$this->image->setImageOrientation( Imagick::ORIENTATION_TOPLEFT );
			}
		} catch ( Exception $e ) {
			return new GC_Error( 'image_flip_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Check if a JPEG image has EXIF Orientation tag and rotate it if needed.
	 *
	 * As ImageMagick copies the EXIF data to the flipped/rotated image, proceed only
	 * if EXIF Orientation can be reset afterwards.
	 *
	 *
	 * @return bool|GC_Error True if the image was rotated. False if no EXIF data or if the image doesn't need rotation.
	 *                       GC_Error if error while rotating.
	 */
	public function maybe_exif_rotate() {
		if ( is_callable( array( $this->image, 'setImageOrientation' ) ) && defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
			return parent::maybe_exif_rotate();
		} else {
			return new GC_Error( 'write_exif_error', __( '此图片无法旋转，因为其内嵌的元数据无法更新。' ) );
		}
	}

	/**
	 * Saves current image to file.
	 *
	 *
	 * @param string $destfilename Optional. Destination filename. Default null.
	 * @param string $mime_type    Optional. The mime-type. Default null.
	 * @return array|GC_Error {'path'=>string, 'file'=>string, 'width'=>int, 'height'=>int, 'mime-type'=>string}
	 */
	public function save( $destfilename = null, $mime_type = null ) {
		$saved = $this->_save( $this->image, $destfilename, $mime_type );

		if ( ! is_gc_error( $saved ) ) {
			$this->file      = $saved['path'];
			$this->mime_type = $saved['mime-type'];

			try {
				$this->image->setImageFormat( strtoupper( $this->get_extension( $this->mime_type ) ) );
			} catch ( Exception $e ) {
				return new GC_Error( 'image_save_error', $e->getMessage(), $this->file );
			}
		}

		return $saved;
	}

	/**
	 * @param Imagick $image
	 * @param string  $filename
	 * @param string  $mime_type
	 * @return array|GC_Error
	 */
	protected function _save( $image, $filename = null, $mime_type = null ) {
		list( $filename, $extension, $mime_type ) = $this->get_output_format( $filename, $mime_type );

		if ( ! $filename ) {
			$filename = $this->generate_filename( null, null, $extension );
		}

		try {
			// Store initial format.
			$orig_format = $this->image->getImageFormat();

			$this->image->setImageFormat( strtoupper( $this->get_extension( $mime_type ) ) );
		} catch ( Exception $e ) {
			return new GC_Error( 'image_save_error', $e->getMessage(), $filename );
		}

		$write_image_result = $this->write_image( $this->image, $filename );
		if ( is_gc_error( $write_image_result ) ) {
			return $write_image_result;
		}

		try {
			// Reset original format.
			$this->image->setImageFormat( $orig_format );
		} catch ( Exception $e ) {
			return new GC_Error( 'image_save_error', $e->getMessage(), $filename );
		}

		// Set correct file permissions.
		$stat  = stat( dirname( $filename ) );
		$perms = $stat['mode'] & 0000666; // Same permissions as parent folder, strip off the executable bits.
		chmod( $filename, $perms );

		return array(
			'path'      => $filename,
			/** This filter is documented in gc-includes/class-gc-image-editor-gd.php */
			'file'      => gc_basename( apply_filters( 'image_make_intermediate_size', $filename ) ),
			'width'     => $this->size['width'],
			'height'    => $this->size['height'],
			'mime-type' => $mime_type,
		);
	}

	/**
	 * Writes an image to a file or stream.
	 *
	 *
	 * @param Imagick $image
	 * @param string  $filename The destination filename or stream URL.
	 * @return true|GC_Error
	 */
	private function write_image( $image, $filename ) {
		if ( gc_is_stream( $filename ) ) {
			/*
			 * Due to reports of issues with streams with `Imagick::writeImageFile()` and `Imagick::writeImage()`, copies the blob instead.
			 * Checks for exact type due to: https://www.php.net/manual/en/function.file-put-contents.php
			 */
			if ( file_put_contents( $filename, $image->getImageBlob() ) === false ) {
				return new GC_Error(
					'image_save_error',
					sprintf(
						/* translators: %s: PHP function name. */
						__( '%s将图片写入流时失败。' ),
						'<code>file_put_contents()</code>'
					),
					$filename
				);
			} else {
				return true;
			}
		} else {
			$dirname = dirname( $filename );

			if ( ! gc_mkdir_p( $dirname ) ) {
				return new GC_Error(
					'image_save_error',
					sprintf(
						/* translators: %s: Directory path. */
						__( '无法创建目录 %s。它的父目录是否可以被服务器写入？' ),
						esc_html( $dirname )
					)
				);
			}

			try {
				return $image->writeImage( $filename );
			} catch ( Exception $e ) {
				return new GC_Error( 'image_save_error', $e->getMessage(), $filename );
			}
		}
	}

	/**
	 * Streams current image to browser.
	 *
	 *
	 * @param string $mime_type The mime type of the image.
	 * @return true|GC_Error True on success, GC_Error object on failure.
	 */
	public function stream( $mime_type = null ) {
		list( $filename, $extension, $mime_type ) = $this->get_output_format( null, $mime_type );

		try {
			// Temporarily change format for stream.
			$this->image->setImageFormat( strtoupper( $extension ) );

			// Output stream of image content.
			header( "Content-Type: $mime_type" );
			print $this->image->getImageBlob();

			// Reset image to original format.
			$this->image->setImageFormat( $this->get_extension( $this->mime_type ) );
		} catch ( Exception $e ) {
			return new GC_Error( 'image_stream_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Strips all image meta except color profiles from an image.
	 *
	 *
	 * @return true|GC_Error True if stripping metadata was successful. GC_Error object on error.
	 */
	protected function strip_meta() {

		if ( ! is_callable( array( $this->image, 'getImageProfiles' ) ) ) {
			return new GC_Error(
				'image_strip_meta_error',
				sprintf(
					/* translators: %s: ImageMagick method name. */
					__( '需要%s来去除图片元数据。' ),
					'<code>Imagick::getImageProfiles()</code>'
				)
			);
		}

		if ( ! is_callable( array( $this->image, 'removeImageProfile' ) ) ) {
			return new GC_Error(
				'image_strip_meta_error',
				sprintf(
					/* translators: %s: ImageMagick method name. */
					__( '需要%s来去除图片元数据。' ),
					'<code>Imagick::removeImageProfile()</code>'
				)
			);
		}

		/*
		 * Protect a few profiles from being stripped for the following reasons:
		 *
		 * - icc:  Color profile information
		 * - icm:  Color profile information
		 * - iptc: Copyright data
		 * - exif: Orientation data
		 * - xmp:  Rights usage data
		 */
		$protected_profiles = array(
			'icc',
			'icm',
			'iptc',
			'exif',
			'xmp',
		);

		try {
			// Strip profiles.
			foreach ( $this->image->getImageProfiles( '*', true ) as $key => $value ) {
				if ( ! in_array( $key, $protected_profiles, true ) ) {
					$this->image->removeImageProfile( $key );
				}
			}
		} catch ( Exception $e ) {
			return new GC_Error( 'image_strip_meta_error', $e->getMessage() );
		}

		return true;
	}

	/**
	 * Sets up Imagick for PDF processing.
	 * Increases rendering DPI and only loads first page.
	 *
	 *
	 * @return string|GC_Error File to load or GC_Error on failure.
	 */
	protected function pdf_setup() {
		try {
			// By default, PDFs are rendered in a very low resolution.
			// We want the thumbnail to be readable, so increase the rendering DPI.
			$this->image->setResolution( 128, 128 );

			// Only load the first page.
			return $this->file . '[0]';
		} catch ( Exception $e ) {
			return new GC_Error( 'pdf_setup_failed', $e->getMessage(), $this->file );
		}
	}

	/**
	 * Load the image produced by Ghostscript.
	 *
	 * Includes a workaround for a bug in Ghostscript 8.70 that prevents processing of some PDF files
	 * when `use-cropbox` is set.
	 *
	 *
	 * @return true|GC_Error
	 */
	protected function pdf_load_source() {
		$filename = $this->pdf_setup();

		if ( is_gc_error( $filename ) ) {
			return $filename;
		}

		try {
			// When generating thumbnails from cropped PDF pages, Imagemagick uses the uncropped
			// area (resulting in unnecessary whitespace) unless the following option is set.
			$this->image->setOption( 'pdf:use-cropbox', true );

			// Reading image after Imagick instantiation because `setResolution`
			// only applies correctly before the image is read.
			$this->image->readImage( $filename );
		} catch ( Exception $e ) {
			// Attempt to run `gs` without the `use-cropbox` option. See #48853.
			$this->image->setOption( 'pdf:use-cropbox', false );

			$this->image->readImage( $filename );
		}

		return true;
	}

}
