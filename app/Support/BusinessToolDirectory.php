<?php

namespace App\Support;

use App\Models\User;

final class BusinessToolDirectory
{
    public static function tools(User $user, bool $creator, bool $canViewOrders): array
    {
        if ($creator) {
            $tools = [
                'Listings & portfolio' => [
                    'studio.listings' => ['Listings & Services', 'Manage your projects, freelance offers, and services.'],
                    'studio.products.index' => ['Product Releases', 'Manage your digital products and their releases.'],
                    'studio.profile.edit' => ['Public Creator Profile', 'Update your portfolio, skills, languages, and contact details.'],
                    'studio.media.index' => ['Media Library', 'Manage files and images for your listings.'],
                ],
                'Customers & delivery' => [
                    'studio.inquiries' => ['Client Inquiries', 'Review questions and requests sent to you.'],
                    'contracts.index' => ['Contracts', 'Manage agreements and milestones on your client work.'],
                    'studio.licenses.index' => ['Product Licenses', 'Review your product licenses and request changes.'],
                ],
            ];

            if ($user->role === 'freelancer') {
                $tools['Customers & delivery']['jobs.applied'] = ['My Proposals', 'Track proposals you have submitted.'];
            }
            if ($canViewOrders) {
                $tools['Customers & delivery']['studio.orders.index'] = ['Sales Orders', 'Review your orders, statuses, and receipts.'];
            }

            return $tools;
        }

        $tools = [];
        $managesPlatform = in_array($user->role, ['super_admin', 'admin'], true);
        if (MarketplaceManagement::mayView($user)) {
            $tools['Marketplace'] = [
                'dashboard.products.index' => ['Digital Products', 'Manage digital products and their publication status.'],
                'dashboard.marketplace.index:project' => ['Projects', 'Manage project listings.', ['type' => 'project']],
                'dashboard.marketplace.index:service' => ['Services', 'Manage service listings.', ['type' => 'service']],
                'dashboard.marketplace.index:freelance' => ['Freelance Offers', 'Manage freelance listings.', ['type' => 'freelance']],
                'dashboard.jobs.index' => ['Jobs', 'Manage job opportunities.'],
            ];
        }
        if (UserManagementAccess::allows($user, 'marketplace_review')) {
            $tools['Marketplace']['dashboard.reviews'] = ['Review Queue', 'Review submissions before publication.'];
        }
        if ($user->role === 'super_admin') {
            $tools['Marketplace']['dashboard.preview-visibility.index'] = ['Preview Visibility', 'Review public preview content and its withdrawal history.'];
        }
        if ($canViewOrders) {
            $tools['Orders & delivery']['dashboard.orders.index'] = ['Marketplace Orders', 'Review order records and receipts.'];
        }
        if ($canViewOrders && $user->role === 'super_admin') {
            $tools['Orders & delivery']['dashboard.payment-methods.index'] = ['Payment Methods', 'Manage available payment methods.'];
        }
        if ($managesPlatform) {
            $tools['Orders & delivery'] = array_merge($tools['Orders & delivery'] ?? [], [
                'dashboard.contracts.index' => ['Contracts', 'Review marketplace contracts and milestones.'],
                'dashboard.contracts.disputes' => ['Contract Disputes', 'Review contract disputes.'],
                'dashboard.vault.index' => ['Digital File Vault', 'Review protected releases and rights evidence.'],
                'dashboard.licenses.index' => ['License Manager', 'Manage product licenses and activation records.'],
                'dashboard.inquiries.index' => ['Service Inquiries', 'Review the marketplace inquiry queue.'],
            ]);
        }
        if (UserManagementAccess::allows($user, 'support_center')) {
            $tools['Support & community']['dashboard.support'] = ['Support Center', 'Reply to tickets and manage their status.'];
        }
        if ($managesPlatform) {
            $tools['Support & community']['dashboard.creator-applications'] = ['Creator Applications', 'Review applications to join the creator marketplace.'];
            $tools['Support & community']['dashboard.account-verifications.index'] = ['Account Verifications', 'Review and manage account verification.'];
            $tools['Publishing'] = [
                'dashboard.pages' => ['Pages', 'Manage public site pages.'],
                'dashboard.posts' => ['Blog Posts', 'Write, edit, and publish blog posts.'],
                'dashboard.policies' => ['Policy Center', 'Manage public policy drafts and publication.'],
                'dashboard.knowledge.index' => ['Knowledge Base', 'Publish help articles for customers and creators.'],
            ];
        }
        if ($user->role === 'super_admin') {
            $tools['Administration'] = [
                'dashboard.users' => ['User Manager', 'Manage platform accounts and their permissions.'],
                'dashboard.privacy.index' => ['Privacy Requests', 'Process account privacy requests.'],
                'dashboard.compliance.index' => ['Compliance Register', 'Track compliance obligations and evidence.'],
                'dashboard.entitlements.index' => ['Product Entitlements', 'Manage access grants for digital products.'],
            ];
        }

        return $tools;
    }
}
