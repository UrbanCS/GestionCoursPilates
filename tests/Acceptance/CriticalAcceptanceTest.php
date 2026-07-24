<?php

declare(strict_types=1);

namespace MemiPilates\Tests\Acceptance;

use MemiPilates\Tests\Support\AcceptanceTestCase;

final class CriticalAcceptanceTest extends AcceptanceTestCase
{
    public function testAt01AvailableSeatCanBeBooked(): void { $this->runScenario('AT-01'); }
    public function testAt02ConcurrentLastSeatHasOneWinner(): void { $this->runScenario('AT-02'); }
    public function testAt03CreditBookingNeedsNoPayment(): void { $this->runScenario('AT-03'); }
    public function testAt04PackagePurchaseWithoutCredit(): void { $this->runScenario('AT-04'); }
    public function testAt05FailedPaymentDoesNotConfirmBooking(): void { $this->runScenario('AT-05'); }
    public function testAt06RepeatedWebhookIsIdempotent(): void { $this->runScenario('AT-06'); }
    public function testAt07OnTimeCancellationRestoresCredit(): void { $this->runScenario('AT-07'); }
    public function testAt08LateCancellationDoesNotRestoreCredit(): void { $this->runScenario('AT-08'); }
    public function testAt09StudioCancellationRestoresCredits(): void { $this->runScenario('AT-09'); }
    public function testAt10FullSessionOffersWaitlist(): void { $this->runScenario('AT-10'); }
    public function testAt11WaitlistOffersFirstClient(): void { $this->runScenario('AT-11'); }
    public function testAt12ExpiredWaitlistOfferMovesForward(): void { $this->runScenario('AT-12'); }
    public function testAt13ValidQrConfirmsAttendance(): void { $this->runScenario('AT-13'); }
    public function testAt14RevokedQrIsRejected(): void { $this->runScenario('AT-14'); }
    public function testAt15SecondScanDoesNotDuplicateAttendance(): void { $this->runScenario('AT-15'); }
    public function testAt16ScanAwardsPointsOnce(): void { $this->runScenario('AT-16'); }
    public function testAt17PaymentAwardsPointsOnce(): void { $this->runScenario('AT-17'); }
    public function testAt18HidEnterTriggersProcessing(): void { $this->runScenario('AT-18'); }
    public function testAt19IncompleteScanIsRejected(): void { $this->runScenario('AT-19'); }
    public function testAt20KioskRestoresFocus(): void { $this->runScenario('AT-20'); }
    public function testAt21ReaderTestCreatesNoAttendance(): void { $this->runScenario('AT-21'); }
    public function testAt22UnauthorizedEmployeeCannotOverride(): void { $this->runScenario('AT-22'); }
    public function testAt23ClientCannotReadAnotherClientData(): void { $this->runScenario('AT-23'); }
    public function testAt24CronCanRunTwiceWithoutDuplication(): void { $this->runScenario('AT-24'); }
    public function testAt25JoomlaInstallIsNonDestructive(): void { $this->runScenario('AT-25'); }
    public function testAt26UninstallHonorsDataPolicy(): void { $this->runScenario('AT-26'); }
    public function testAt27RejoinedWaitlistConsumesCreditForNewLifecycle(): void { $this->runScenario('AT-27'); }
    public function testAt28DirectSessionPaymentConfirmsBooking(): void { $this->runScenario('AT-28'); }
    public function testAt29FailedOrExpiredPaymentHoldReleasesCapacity(): void { $this->runScenario('AT-29'); }
    public function testAt30SuperUserCanUseCompleteFrontendPortal(): void { $this->runScenario('AT-30'); }
    public function testAt31FrontendPortalRejectsUnauthorizedUsers(): void { $this->runScenario('AT-31'); }
    public function testAt32FrontendSettingsPreserveSquareSecrets(): void { $this->runScenario('AT-32'); }
}
