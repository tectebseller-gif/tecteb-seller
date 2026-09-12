<?php
declare(strict_types=1);

namespace Tecteb\Marketplace\Modules\Vendor;

use Tecteb\Marketplace\Contracts\Auth\AuthenticationBridgeInterface;
use Tecteb\Marketplace\Contracts\CapabilityCheckerInterface;
use Tecteb\Marketplace\Contracts\ClockInterface;
use Tecteb\Marketplace\Contracts\ContainerInterface;
use Tecteb\Marketplace\Contracts\DatabaseInterface;
use Tecteb\Marketplace\Contracts\Files\PrivateFileStorageInterface;
use Tecteb\Marketplace\Contracts\ModuleInterface;
use Tecteb\Marketplace\Contracts\ModuleKind;
use Tecteb\Marketplace\Contracts\ModuleManifest;
use Tecteb\Marketplace\Contracts\OptionStoreInterface;
use Tecteb\Marketplace\Contracts\Otp\OtpProviderInterface;
use Tecteb\Marketplace\Core\Audit\AuditLogger;
use Tecteb\Marketplace\Modules\Vendor\Application\ConfigureDocumentTypes;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\DocumentTypeRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\MobileVerification;
use Tecteb\Marketplace\Modules\Vendor\Application\ReviewApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\SaveApplicationDraft;
use Tecteb\Marketplace\Modules\Vendor\Application\SubmitApplication;
use Tecteb\Marketplace\Modules\Vendor\Application\UploadApplicationDocument;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorRepositoryInterface;
use Tecteb\Marketplace\Modules\Vendor\Application\VendorWorkspaceFactory;
use Tecteb\Marketplace\Modules\Vendor\Domain\ApplicationStateMachine;
use Tecteb\Marketplace\Modules\Vendor\Domain\UploadPolicy;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbDocumentRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbDocumentTypeRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\DbVendorRepository;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\PrivateUploadStorage;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\VendorHooks;
use Tecteb\Marketplace\Modules\Vendor\Infrastructure\WordPress\WpAuthenticationBridge;

/**
 * Vendor onboarding: application, documents, review, and the vendor's own
 * dashboard at /vendor/ (plan §§2–7).
 *
 * Does not require WooCommerce. Becoming a vendor is about identity and
 * paperwork; nothing here touches products or orders, so a site can run the
 * whole onboarding flow before WooCommerce matters.
 */
final class VendorModule implements ModuleInterface
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            'vendor',
            $this->version(),
            'فروشندگان',
            ModuleKind::Operational,
            ['core', 'environment-guard', 'admin'],
            false,
            'درخواست فروشندگی، مدارک، بررسی مدیر و پیشخوان فروشنده'
        );
    }

    public function register(ContainerInterface $c): void
    {
        $c->bind(AuthenticationBridgeInterface::class, static fn () => new WpAuthenticationBridge());
        $c->bind(PrivateFileStorageInterface::class, static fn () => new PrivateUploadStorage());
        $c->bind(ApplicationStateMachine::class, static fn () => new ApplicationStateMachine());
        $c->bind(UploadPolicy::class, static fn () => new UploadPolicy());

        $c->bind(VendorRepositoryInterface::class, static fn (ContainerInterface $c) => new DbVendorRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(DocumentTypeRepositoryInterface::class, static fn (ContainerInterface $c) => new DbDocumentTypeRepository(
            $c->get(DatabaseInterface::class),
            $c->get(OptionStoreInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(DocumentRepositoryInterface::class, static fn (ContainerInterface $c) => new DbDocumentRepository(
            $c->get(DatabaseInterface::class),
            $c->get(ClockInterface::class)
        ));
        $c->bind(MobileVerification::class, static fn (ContainerInterface $c) => new MobileVerification(
            $c->get(OtpProviderInterface::class)
        ));

        $c->bind(SaveApplicationDraft::class, static fn (ContainerInterface $c) => new SaveApplicationDraft(
            $c->get(VendorRepositoryInterface::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(UploadApplicationDocument::class, static fn (ContainerInterface $c) => new UploadApplicationDocument(
            $c->get(VendorRepositoryInterface::class),
            $c->get(DocumentTypeRepositoryInterface::class),
            $c->get(DocumentRepositoryInterface::class),
            $c->get(PrivateFileStorageInterface::class),
            $c->get(UploadPolicy::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(SubmitApplication::class, static fn (ContainerInterface $c) => new SubmitApplication(
            $c->get(VendorRepositoryInterface::class),
            $c->get(DocumentTypeRepositoryInterface::class),
            $c->get(DocumentRepositoryInterface::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ReviewApplication::class, static fn (ContainerInterface $c) => new ReviewApplication(
            $c->get(VendorRepositoryInterface::class),
            $c->get(ApplicationStateMachine::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(ConfigureDocumentTypes::class, static fn (ContainerInterface $c) => new ConfigureDocumentTypes(
            $c->get(DocumentTypeRepositoryInterface::class),
            $c->get(AuditLogger::class),
            $c->get(CapabilityCheckerInterface::class)
        ));
        $c->bind(VendorWorkspaceFactory::class, static fn (ContainerInterface $c) => new VendorWorkspaceFactory(
            $c->get(VendorRepositoryInterface::class),
            $c->get(DocumentTypeRepositoryInterface::class),
            $c->get(DocumentRepositoryInterface::class),
            $c->get(MobileVerification::class)
        ));
    }

    public function boot(ContainerInterface $container): void
    {
        VendorHooks::register($container);
    }

    private function version(): string
    {
        return class_exists(\Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::class, false)
            ? \Tecteb\Marketplace\Infrastructure\WordPress\Bootstrap::pluginVersion()
            : '0.0.0';
    }
}
