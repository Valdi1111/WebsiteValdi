import React, { useState } from "react";
import { Button, Grid, Space, Dropdown } from "antd";
import { PlusOutlined, EditOutlined, DeleteOutlined, DownOutlined } from "@ant-design/icons";
import StandardTable from "@CoreBundle/components/StandardTable";
import CredentialDetailModal from "@PasswordsBundle/components/credentials/CredentialDetailModal";
import { useBackendApi } from "@PasswordsBundle/components/BackendApiContext";

const { useBreakpoint } = Grid;

export default function CredentialsTable() {
    const screens = useBreakpoint();
    const isMobile = !screens.sm;

    const [modalOpen, setModalOpen] = useState(false);
    const [credential, setCredential] = useState({});
    const api = useBackendApi();

    const columns = [
        {
            title: "ID",
            dataIndex: "id",
            fixed: "left",
            hidden: true,
            filterType: "number",
        },
        {
            title: "Name",
            dataIndex: "name",
            sorter: true,
            defaultSortOrder: "ascend",
            filterType: "text",
        },
        {
            title: "Tags",
            dataIndex: "tags",
            valueType: "tags",
            filterType: "text",
            randomColor: true,
        },
        {
            title: "Type",
            dataIndex: "type",
            hidden: true,
            filterType: "text",
        },
        {
            title: "Actions",
            key: "actions",
            dataIndex: "actions",
            fixed: "right",
            width: isMobile ? 120 : 180,
            render: (_, record) => (
                <Space size="small">
                    <Button
                        size="small"
                        icon={<EditOutlined />}
                        onClick={(e) => {
                            e.stopPropagation();
                            setCredential({ id: record.id, type: record.type });
                            setModalOpen(true);
                        }}
                    >
                        {!isMobile && "Edit"}
                    </Button>
                    <Button
                        size="small"
                        danger
                        icon={<DeleteOutlined />}
                        onClick={(e) => {
                            e.stopPropagation();
                            // TODO: Add confirmation popconfirm/modal and trigger delete endpoint
                        }}
                    >
                        {!isMobile && "Delete"}
                    </Button>
                </Space>
            ),
        },
    ];

    const addMenu = {
        items: [
            {
                key: "device",
                label: "Add Device",
                onClick: () => {
                    setCredential({ id: null, type: "device" });
                    setModalOpen(true);
                },
            },
            {
                key: "website",
                label: "Add Website",
                onClick: () => {
                    setCredential({ id: null, type: "website" });
                    setModalOpen(true);
                },
            },
        ],
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
            <CredentialDetailModal
                id={credential.id}
                type={credential.type}
                open={modalOpen}
                setOpen={setModalOpen}
            />

            <StandardTable
                title="Credentials Vault"
                subtitle="Manage saved passwords, website credentials, and device authentication keys"
                columns={columns}
                fetchData={(params) => api.withErrorHandling().credentials().table(params)}
                extraToolbarActions={
                    <Dropdown menu={addMenu} trigger={["click"]}>
                        <Button type="primary" icon={<PlusOutlined />}>
                            New Credential <DownOutlined />
                        </Button>
                    </Dropdown>
                }
            />
        </div>
    );
}