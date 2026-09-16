import HoyoverseGameProfileSettingsDrawer from "@HoyoverseBundle/components/game-profile/HoyoverseGameProfileSettingsDrawer";
import HoyoverseAddAccountModal from "@HoyoverseBundle/components/account/HoyoverseAddAccountModal";
import HoyoverseAccountCard from "@HoyoverseBundle/components/account/HoyoverseAccountCard";
import { useBackendApi } from "@HoyoverseBundle/components/BackendApiContext";
import React, { useState, useEffect, useCallback } from "react";
import { Button, Typography, Space, Empty, Spin } from "antd";
import { PlusOutlined } from "@ant-design/icons";

const { Title, Text } = Typography;

export default function HoyoverseAccountsPage() {
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
        <div style={{ flex: 1, padding: 24, overflowY: "auto" }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 24 }}>
                <div>
                    <Title level={3} style={{ margin: 0 }}>HoYoverse Accounts</Title>
                    <Text type="secondary">
                        Manage your HoYoverse credentials, sync linked game profiles, and configure automation settings
                    </Text>
                </div>
                <Button
                    type="primary"
                    icon={<PlusOutlined />}
                    onClick={() => setIsAddModalOpen(true)}
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
                <Space orientation="vertical" size="large" style={{ width: "100%" }}>
                    {accounts.map((acc) => (
                        <HoyoverseAccountCard
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

            <HoyoverseAddAccountModal
                open={isAddModalOpen}
                loading={submittingAccount}
                onCancel={() => setIsAddModalOpen(false)}
                onSubmit={handleCreateAccount}
            />

            <HoyoverseGameProfileSettingsDrawer
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