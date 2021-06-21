<?php

namespace MediaWiki\Extension\PageViewInfoGA;

final class Constants {

	// These are tightly coupled to extension.json's config.
	/**
	 * @var string
	 */
	public const CONFIG_KEY_TRACKING_ID = 'PageViewInfoGATrackingID';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_CREDENTIALS_FILE = 'PageViewInfoGACredentialsFile';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_PROFILE_ID = 'PageViewInfoGAProfileId';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_WRITE_CUSTOM_DIMENSIONS = 'PageViewInfoGAWriteCustomDimensions';

	/**
	 * @var string
	 */
	public const CONFIG_KEY_CUSTOM_MAP = "PageViewInfoGAWriteCustomMap";

	/**
	 * @var string
	 */
	public const CONFIG_KEY_READ_CUSTOM_DIMENSIONS = 'PageViewInfoGAReadCustomDimensions';
}
