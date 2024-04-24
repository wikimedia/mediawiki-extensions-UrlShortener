<?php
/**
 * A special page that creates QR codes from allowed URLs
 *
 * @file
 * @ingroup Extensions
 * @license Apache-2.0
 */

namespace MediaWiki\Extension\UrlShortener;

use MediaWiki\Config\ConfigFactory;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Status\Status;
use MediaWiki\Utils\UrlUtils;

class SpecialQrCode extends SpecialUrlShortener {

	private int $shortenLimit;

	private UrlShortenerUtils $utils;

	public function __construct(
		UrlShortenerUtils $utils,
		UrlUtils $urlUtils,
		ConfigFactory $configFactory
	) {
		$config = $configFactory->makeConfig( 'urlshortener' );
		$this->shortenLimit = $config->get( 'UrlShortenerQrCodeShortenLimit' );
		parent::__construct( $utils, $urlUtils, 'QrCode' );
		$this->utils = $utils;
	}

	/**
	 * @param string|null $par
	 */
	public function execute( $par ) {
		if ( !$this->getConfig()->get( 'UrlShortenerEnableQrCode' ) ) {
			$this->setHeaders();
			$this->getOutput()->addWikiMsg( 'urlshortener-qrcode-disabled' );
			return;
		}
		parent::execute( $par );
		$this->addHelpLink( 'Help:QrCode' );
	}

	/**
	 * @param HTMLForm $form
	 * @param string $module
	 */
	protected function alterForm( HTMLForm $form, string $module = 'ext.urlShortener.special' ) {
		parent::alterForm( $form, 'ext.urlShortener.qrCode.special' );

		if ( $this->resultStatus !== null ) {
			// Once remove the closing tag for the .ext-urlshortener-container element opened
			// in parent::alterForm() because we want to add another element into the wrapper
			$form->setPostHtml( '' );
			// We have to use Html::rawElement() because we need to link to a data URI,
			// which is disallowed by OOUI\Tag::isSafeUrl().
			// @phan-suppress-next-line SecurityCheck-XSS
			$form->addPostHtml( $this->getQrCodeHtml() );
			// Closes the .ext-urlshortener-container element again
			$form->setPostHtml( Html::closeElement( 'div' ) );
		}
	}

	/**
	 * Generate the form used to input the URL to shorten.
	 * @return array A form definition that can be used by HTMLForm
	 */
	protected function getFormFields() {
		return [
			'url' => [
				'validation-callback' => [ $this, 'validateURL' ],
				'required' => true,
				'type' => 'textwithbutton',
				'inputtype' => 'url',
				'buttontype' => 'submit',
				'buttondefault' => $this->msg( 'urlshortener-qrcode-form-submit' )->text(),
				'buttonflags' => [ 'primary', 'progressive' ],
				'buttonid' => 'mw-urlshortener-submit',
				'name' => 'url',
				'label-message' => 'urlshortener-qrcode-url-label',
				'autofocus' => true,
				'id' => 'ext-urlshortener-url-input',
				'help' => $this->getApprovedDomainsMessage()->parse(),
				'placeholder' => $this->msg( 'urlshortener-url-input-label' )->text(),
			],
		];
	}

	/**
	 * Process the form on POST submission.
	 *
	 * Creates the short URL and displays it back to the user.
	 *
	 * @param array $data
	 * @return bool|Status True for success, false for didn't-try, Status (with errors) on failure
	 */
	public function onSubmit( array $data ) {
		$out = $this->getOutput();
		$out->enableOOUI();
		$out->addModuleStyles( [
			'codex-styles',
			'ext.urlShortener.qrCode.special.styles'
		] );
		if ( $data['url'] === null ) {
			return false;
		}
		$status = $this->utils->getQrCode(
			$data['url'],
			$this->shortenLimit,
			$this->getUser(),
			true
		);
		if ( !$status->isOK() ) {
			return $status;
		}
		$this->setShortenedUrlResultField( $status->getValue() );
		$this->resultStatus = $status;
		return true;
	}

	/**
	 * Don't list this page if in read only mode or QR Codes are not enabled.
	 *
	 * @return bool
	 */
	public function isListed() {
		return parent::isListed() && $this->getConfig()->get( 'UrlShortenerEnableQrCode' );
	}

	/**
	 * Create the QR Code based on the url
	 *
	 * @return string
	 */
	private function getQrCodeHtml(): string {
		$qrCodeUri = $this->resultStatus->getValue()['qrcode'];
		$qrCodeImage = Html::element( 'img', [
			'src' => $qrCodeUri
		] );
		$qrCodeNode = Html::rawElement( 'div', [
			'class' => 'ext-urlshortener-qrcode'
		], $qrCodeImage );
		return Html::rawElement(
			'div',
			[ 'class' => 'ext-urlshortener-qrcode-container' ],
			$qrCodeNode . $this->getDownloadButton( $qrCodeUri )
		);
	}

	/**
	 * @param string $qrCodeUri
	 * @return string
	 */
	private function getDownloadButton( string $qrCodeUri ): string {
		$downloadIcon = Html::element( 'span', [
			'class' => 'cdx-icon cdx-icon--medium'
		] );
		$classes = [
			'ext-urlshortener-download-qr-button',
			'cdx-button',
			'cdx-button--fake-button',
			'cdx-button--fake-button--enabled'
		];
		$content = $downloadIcon . $this->msg( 'urlshortener-toolbox-qrcode' )->text();
		return Html::rawElement( 'a', [
			'class' => implode( ' ', $classes ),
			'href' => $qrCodeUri,
			'download' => 'qrcode.svg'
		], $content );
	}
}
