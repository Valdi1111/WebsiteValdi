import GameProfileSettingsDrawer from "@HoyoverseBundle/components/game-profile/GameProfileSettingsDrawer";
import AddAccountModal from "@HoyoverseBundle/components/account/AddAccountModal";
import AccountCard from "@HoyoverseBundle/components/account/AccountCard";

import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
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
    const [submittingAccount, setSubmittingAccount] = useState(false);
    const [selectedTarget, setSelectedTarget] = useState(null);
    const [isSettingsOpen, setIsSettingsOpen] = useState(false);

    const api = useBackendApi();

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
                onSuccess?.();
                loadAccounts();
            })
            .finally(() => {
                setSubmittingAccount(false);
            });
    };

    const handleSyncAccount = (accountId) => {
        setSyncingAccountId(accountId);
        api
            .withErrorHandling()
            .accounts()
            .sync(accountId)
            .then(() => {
                loadAccounts();
            })
            .finally(() => {
                setSyncingAccountId(null);
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

    const handleOpenSettings = (accountId, canRedeemCodes, profileId) => {
        setSelectedTarget({ accountId, canRedeemCodes, profileId });
        setIsSettingsOpen(true);
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
                        HoYoverse Accounts
                    </Title>
                    <Text type="secondary" style={{ fontSize: isMobile ? 12 : 13 }}>
                        Manage your HoYoverse credentials, sync linked game profiles, and configure automation settings
                    </Text>
                </div>
                <Button
                    type="primary"
                    icon={<PlusOutlined />}
                    onClick={() => setIsAddModalOpen(true)}
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
                <Empty description="No HoYoverse accounts found" />
            ) : (
                <Space orientation="vertical" size={isMobile ? "middle" : "large"} style={{ width: "100%" }}>
                    {accounts.map((acc) => (
                        <AccountCard
                            key={acc.id}
                            account={acc}
                            isSyncing={syncingAccountId === acc.id}
                            onSync={handleSyncAccount}
                            onDelete={handleDeleteAccount}
                            onOpenProfileSettings={handleOpenSettings}
                        />
                    ))}
                </Space>
            )}

            <AddAccountModal
                open={isAddModalOpen}
                loading={submittingAccount}
                onCancel={() => setIsAddModalOpen(false)}
                onSubmit={handleCreateAccount}
            />

            <GameProfileSettingsDrawer
                open={isSettingsOpen}
                accountId={selectedTarget?.accountId}
                profileId={selectedTarget?.profileId}
                accountCanRedeemCodes={selectedTarget?.canRedeemCodes}
                onClose={() => {
                    setIsSettingsOpen(false);
                    setSelectedTarget(null);
                }}
                onSaved={loadAccounts}
            />
        </div>
    );
}