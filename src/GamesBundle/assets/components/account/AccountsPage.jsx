import AccountCard from "@GamesBundle/components/account/AccountCard";
import { useBackendApi } from "@GamesBundle/components/BackendApiContext";
import { useGameProvider } from "@GamesBundle/components/GameProviderContext";
import GameProfileSettingsDrawer from "@GamesBundle/components/game-profile/GameProfileSettingsDrawer";
import GameProfileStatusDrawer from "@GamesBundle/components/game-profile/GameProfileStatusDrawer";
import React, { useState, useEffect, useCallback } from "react";
import { Button, Typography, Space, Empty, Spin, Grid } from "antd";
import { PlusOutlined } from "@ant-design/icons";

const { Title, Text } = Typography;
const { useBreakpoint } = Grid;

export default function AccountsPage() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const [accounts, setAccounts] = useState([]);
    const [loading, setLoading] = useState(false);
    const [syncingAccountId, setSyncingAccountId] = useState(null);
    const [isAddModalOpen, setIsAddModalOpen] = useState(false);
    const [reloginAccount, setReloginAccount] = useState(null);
    const [submittingAccount, setSubmittingAccount] = useState(false);
    // { accountId, profileId }: the account itself is read from the list so it stays fresh after reloads
    const [selectedTarget, setSelectedTarget] = useState(null);
    const [isSettingsOpen, setIsSettingsOpen] = useState(false);
    // Same shape, kept apart so closing one drawer never clears the target of the other
    const [statusTarget, setStatusTarget] = useState(null);
    const [isStatusOpen, setIsStatusOpen] = useState(false);

    const api = useBackendApi();
    const { label, AddAccountModal } = useGameProvider();

    const selectedAccount = accounts.find((acc) => acc.id === selectedTarget?.accountId) ?? null;
    const statusAccount = accounts.find((acc) => acc.id === statusTarget?.accountId) ?? null;

    const loadAccounts = useCallback(() => {
        setLoading(true);
        api
            .withErrorHandling()
            .accounts()
            .get()
            .then((res) => {
                setAccounts(res.data || []);
            })
            .finally(() => {
                setLoading(false);
            });
    }, [api]);

    useEffect(() => {
        loadAccounts();
    }, [loadAccounts]);

    const handleCreateAccount = (values, onSuccess) => {
        setSubmittingAccount(true);
        api
            .withErrorHandling()
            .accounts()
            .add(values)
            .then(() => {
                setIsAddModalOpen(false);
                setReloginAccount(null);
                onSuccess?.();
                loadAccounts();
            })
            .finally(() => {
                setSubmittingAccount(false);
            });
    };

    const handleLoginSuccess = () => {
        setIsAddModalOpen(false);
        setReloginAccount(null);
        loadAccounts();
    };

    const handleOpenRelogin = (account) => {
        setReloginAccount(account);
        setIsAddModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsAddModalOpen(false);
        setReloginAccount(null);
    };

    const handleSyncAccount = (accountId) => {
        setSyncingAccountId(accountId);
        api
            .withErrorHandling()
            .accounts()
            .sync(accountId)
            // Reload on failure too: a failed sync may have updated the account status (e.g. expired login)
            .finally(() => {
                setSyncingAccountId(null);
                loadAccounts();
            });
    };

    const handleDeleteAccount = (accountId) => {
        api
            .withErrorHandling()
            .accounts()
            .delete(accountId)
            .then(() => {
                loadAccounts();
            });
    };

    const handleOpenSettings = (accountId, profileId) => {
        setSelectedTarget({ accountId, profileId });
        setIsSettingsOpen(true);
    };

    const handleOpenStatus = (accountId, profileId) => {
        setStatusTarget({ accountId, profileId });
        setIsStatusOpen(true);
    };

    return (
        <div
            style={{
                flex: 1,
                padding: isMobile ? "12px 10px" : 24,
                overflowY: "auto",
                boxSizing: "border-box",
            }}
        >
            <div
                style={{
                    display: "flex",
                    justifyContent: "space-between",
                    alignItems: isMobile ? "stretch" : "flex-start",
                    flexDirection: isMobile ? "column" : "row",
                    gap: isMobile ? 12 : 16,
                    marginBottom: isMobile ? 16 : 24,
                }}
            >
                <div style={{ maxWidth: 600 }}>
                    <Title level={isMobile ? 4 : 3} style={{ margin: 0 }}>
                        {label} Accounts
                    </Title>
                    <Text type="secondary" style={{ fontSize: isMobile ? 12 : 13 }}>
                        Manage your {label} credentials, sync linked game profiles, and configure automation settings
                    </Text>
                </div>
                <Button
                    type="primary"
                    icon={<PlusOutlined />}
                    onClick={() => {
                        setReloginAccount(null);
                        setIsAddModalOpen(true);
                    }}
                    style={{ alignSelf: isMobile ? "flex-start" : "auto" }}
                >
                    Add Account
                </Button>
            </div>

            {loading && accounts.length === 0 ? (
                <div style={{ textAlign: "center", padding: 60 }}>
                    <Spin size="large" />
                </div>
            ) : accounts.length === 0 ? (
                <Empty description={`No ${label} accounts found`} />
            ) : (
                <Space orientation="vertical" size={isMobile ? "middle" : "large"} style={{ width: "100%" }}>
                    {accounts.map((acc) => (
                        <AccountCard
                            key={acc.id}
                            account={acc}
                            isSyncing={syncingAccountId === acc.id}
                            onSync={handleSyncAccount}
                            onRelogin={handleOpenRelogin}
                            onDelete={handleDeleteAccount}
                            onOpenProfileSettings={handleOpenSettings}
                            onOpenProfileStatus={handleOpenStatus}
                        />
                    ))}
                </Space>
            )}

            <AddAccountModal
                open={isAddModalOpen}
                loading={submittingAccount}
                reloginAccount={reloginAccount}
                onCancel={handleCloseModal}
                onSubmit={handleCreateAccount}
                onLoginSuccess={handleLoginSuccess}
            />

            <GameProfileSettingsDrawer
                open={isSettingsOpen}
                account={selectedAccount}
                profileId={selectedTarget?.profileId}
                onClose={() => {
                    setIsSettingsOpen(false);
                    setSelectedTarget(null);
                }}
                onSaved={loadAccounts}
            />

            {/* The target is kept on close so the header does not vanish during the close animation;
                the next open replaces it */}
            <GameProfileStatusDrawer
                open={isStatusOpen}
                account={statusAccount}
                profileId={statusTarget?.profileId}
                onClose={() => setIsStatusOpen(false)}
            />
        </div>
    );
}
