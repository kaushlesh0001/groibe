<?php

namespace App\Enums;

enum FeatureFlag: string
{
    case OpenRoles = 'open_roles';
    case PublicDirectories = 'public_directories';
    case ProofOfWorkPublic = 'proof_of_work_public';
    case Messaging = 'messaging';
    case ContributorReviewsOfClients = 'contributor_reviews_of_clients';
    case PublicClientReviews = 'public_client_reviews';
    case ClientCanViewShortlist = 'client_can_view_shortlist';
    case SelfServeClientSignup = 'self_serve_client_signup';
    case ContributorSignup = 'contributor_signup';
    case TwoFactorAdmin = 'two_factor_admin';

    /** Defaults per spec section 18. */
    public function default(): bool
    {
        return match ($this) {
            self::PublicClientReviews, self::ClientCanViewShortlist => false,
            default => true,
        };
    }

    public function notes(): string
    {
        return match ($this) {
            self::OpenRoles => 'Open roles board, applications, homepage section',
            self::PublicDirectories => 'Public contributor and organization directories',
            self::ProofOfWorkPublic => 'Public /work pages',
            self::Messaging => 'Project threads and support threads',
            self::ContributorReviewsOfClients => 'Contributors can review clients (admin-only visibility)',
            self::PublicClientReviews => 'Show client reviews publicly',
            self::ClientCanViewShortlist => 'Global default for the per-project setting',
            self::SelfServeClientSignup => 'Public client registration (else invite-only)',
            self::ContributorSignup => 'Public contributor registration',
            self::TwoFactorAdmin => 'Enforce 2FA for admins',
        };
    }
}
