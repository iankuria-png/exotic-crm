<?php

namespace App\Billing\Providers;

use App\Billing\Support\BillingRail;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\ProviderCapability;
use App\Billing\Support\ProviderCapabilitySet;
use App\Billing\Support\ProviderFamily;
use App\Billing\Support\ProviderOperationType;
use App\Billing\Support\SettlementSemantics;
use App\Billing\Support\TransportMode;

final class ProviderCatalog
{
    /**
     * @return list<StaticProviderAdapter>
     */
    public static function adapters(): array
    {
        return array_map(
            static fn (ProviderDefinition $definition): StaticProviderAdapter => new StaticProviderAdapter($definition),
            self::definitions()
        );
    }

    /**
     * @return list<ProviderDefinition>
     */
    public static function definitions(): array
    {
        return [
            new ProviderDefinition(
                key: 'pesapal',
                label: 'Pesapal',
                family: ProviderFamily::Pesapal,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::SandboxAvailable,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                        BillingSurface::ProxyHostedCheckout,
                    ],
                    rails: [BillingRail::Card, BillingRail::MobileMoney],
                    transportModes: [TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                currencyCodes: ['KES', 'UGX', 'TZS', 'USD'],
                countryCodes: ['KE', 'UG', 'TZ'],
                restrictions: [
                    'hosted_checkout_only' => true,
                    'supports_wallet_topup' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => true,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'paystack',
                label: 'Paystack',
                family: ProviderFamily::Paystack,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::SandboxAvailable,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                        BillingSurface::ProxyHostedCheckout,
                    ],
                    rails: [BillingRail::Card, BillingRail::MobileMoney, BillingRail::BankTransfer],
                    transportModes: [TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                currencyCodes: ['GHS', 'KES', 'NGN', 'USD', 'ZAR'],
                countryCodes: ['GH', 'KE', 'NG', 'ZA'],
                restrictions: [
                    'hosted_checkout_only' => true,
                    'near_term_deferred' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => true,
                    'deferred' => true,
                ]
            ),
            new ProviderDefinition(
                key: 'mpesa_stk',
                label: 'M-Pesa STK',
                family: ProviderFamily::Daraja,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Polling,
                        ProviderCapability::StatusQueries,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionPush,
                        BillingSurface::WalletAutoRenew,
                    ],
                    rails: [BillingRail::MobileMoney],
                    transportModes: [TransportMode::Push, TransportMode::DjangoProxy],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                    ],
                    settlementSemantics: [SettlementSemantics::ConfirmationBased],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Transitional]
                ),
                aliases: ['m-pesa-stk'],
                currencyCodes: ['KES'],
                countryCodes: ['KE'],
                restrictions: [
                    'transitional_transport_alias' => true,
                    'proxy_checkout_supported' => false,
                ],
                meta: [
                    'legacy_wallet_selectable' => true,
                    'compatibility_alias_for' => 'daraja',
                ]
            ),
            new ProviderDefinition(
                key: 'daraja',
                label: 'Safaricom Daraja',
                family: ProviderFamily::Daraja,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::Polling,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionPush,
                        BillingSurface::WalletAutoRenew,
                    ],
                    rails: [BillingRail::MobileMoney],
                    transportModes: [TransportMode::Push, TransportMode::ServerToServerCollection],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::ConfirmationBased],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                aliases: ['safaricom_daraja'],
                currencyCodes: ['KES'],
                countryCodes: ['KE'],
                restrictions: [
                    'kenya_only' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'kopokopo',
                label: 'KopoKopo',
                family: ProviderFamily::Kopokopo,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::Polling,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionPush,
                        BillingSurface::WalletAutoRenew,
                    ],
                    rails: [BillingRail::MobileMoney],
                    transportModes: [TransportMode::Push, TransportMode::ServerToServerCollection],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::ConfirmationBased],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                aliases: ['kopo', 'k2'],
                currencyCodes: ['KES'],
                countryCodes: ['KE'],
                restrictions: [
                    'kenya_only' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'pawapay',
                label: 'pawaPay',
                family: ProviderFamily::Pawapay,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::Polling,
                        ProviderCapability::SandboxAvailable,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                    ],
                    rails: [BillingRail::MobileMoney],
                    transportModes: [TransportMode::ServerToServerCollection, TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                countryCodes: ['GH', 'KE', 'MW', 'MZ', 'UG', 'TZ', 'ZM'],
                restrictions: [
                    'adult_content_suitability_requires_review' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'elemitech',
                label: 'ElemiTech',
                family: ProviderFamily::Elemitech,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::Polling,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                    ],
                    rails: [BillingRail::MobileMoney, BillingRail::BankTransfer],
                    transportModes: [TransportMode::ServerToServerCollection, TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                countryCodes: ['GH', 'KE', 'UG', 'TZ', 'ZM'],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'dusupay',
                label: 'DusuPay',
                family: ProviderFamily::Dusupay,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::Polling,
                    ],
                    surfaces: [
                        BillingSurface::WalletFunding,
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                    ],
                    rails: [BillingRail::MobileMoney, BillingRail::BankTransfer, BillingRail::Card],
                    transportModes: [TransportMode::ServerToServerCollection, TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                countryCodes: ['GH', 'KE', 'UG', 'TZ', 'ZM'],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'nowpayments',
                label: 'NOWPayments',
                family: ProviderFamily::Nowpayments,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::PartialAmountTolerance,
                    ],
                    surfaces: [
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                    ],
                    rails: [BillingRail::Crypto],
                    transportModes: [TransportMode::InvoiceAddress, TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::InvoiceExpiryBased],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                restrictions: [
                    'wallet_funding_supported' => false,
                    'subscription_push_supported' => false,
                ],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => false,
                ]
            ),
            new ProviderDefinition(
                key: 'paypal',
                label: 'PayPal',
                family: ProviderFamily::Paypal,
                capabilities: new ProviderCapabilitySet(
                    capabilities: [
                        ProviderCapability::Webhooks,
                        ProviderCapability::StatusQueries,
                        ProviderCapability::SandboxAvailable,
                    ],
                    surfaces: [
                        BillingSurface::SubscriptionLink,
                        BillingSurface::SelfCheckout,
                    ],
                    rails: [BillingRail::Card, BillingRail::BankTransfer],
                    transportModes: [TransportMode::Redirect],
                    operationTypes: [
                        ProviderOperationType::Initiate,
                        ProviderOperationType::StatusQuery,
                        ProviderOperationType::WebhookVerify,
                    ],
                    settlementSemantics: [SettlementSemantics::Delayed],
                    executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
                ),
                restrictions: [
                    'near_term_deferred' => true,
                ],
                meta: [
                    'legacy_wallet_selectable' => false,
                    'deferred' => true,
                ]
            ),
        ];
    }
}
