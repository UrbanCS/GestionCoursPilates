/*
 * Adapter for the locally bundled, MIT-licensed qr-scanner 1.4.2 package.
 * kiosk.js consumes this tiny interface only when BarcodeDetector is absent.
 */
(() => {
  'use strict';

  if (!window.QrScanner) {
    return;
  }

  window.MemiPilatesQrDecoder = {
    async detect(source) {
      try {
        const result = await window.QrScanner.scanImage(source, {
          alsoTryWithoutScanRegion: true,
          returnDetailedScanResult: true
        });
        const value = typeof result === 'string' ? result : result && result.data;
        return value ? [value] : [];
      } catch (error) {
        // qr-scanner rejects frames without a QR code; the camera loop simply
        // continues with the next frame and never exposes error details.
        return [];
      }
    }
  };
})();
