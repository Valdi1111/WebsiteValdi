import CredentialDetailModal from "@PasswordsBundle/components/credentials/CredentialDetailModal";
import StandardTable from "@CoreBundle/components/StandardTable";
import { useBackendApi } from "@PasswordsBundle/components/BackendApiContext";
import { DeleteOutlined, EditOutlined, PlusOutlined } from "@ant-design/icons";
import { Button, FloatButton, Space } from "antd";
import React from "react";

export default function CredentialsTable() {
    const [modalOpen, setModalOpen] = React.useState(false);
    const [credential, setCredential] = React.useState({});

    const api = useBackendApi();

    function onRowClick(record, rowIndex) {
        return {
            // click row
            onClick: e => {
            },
            // double click row
            onDoubleClick: e => {
            },
            // right button click row
            onContextMenu: e => {
            },
            // mouse enter row
            onMouseEnter: e => {
            },
            // mouse leave row
            onMouseLeave: e => {
            },
        };
    }

    return <>
        <CredentialDetailModal id={credential.id} type={credential.type} open={modalOpen} setOpen={setModalOpen}/>
        <StandardTable
            backendFunction={api.withErrorHandling().credentials().table}
            tableStyle={{ overflowY: 'auto', width: '100%' }}
            tableOnRow={onRowClick}
            extraColumnsAfter={[
                {
                    title: "Actions",
                    key: "actions",
                    dataIndex: "",
                    fixed: "right",
                    render: (_, record) => <Space size="middle">
                        <Button icon={<EditOutlined/>} onClick={() => {
                            setModalOpen(true);
                            setCredential({ id: record.id, type: record.type });
                        }}>Edit</Button>
                        <Button icon={<DeleteOutlined/>} danger>Delete</Button>
                    </Space>,
                }
            ]}
        />
        <FloatButton.Group shape="circle" style={{ insetInlineEnd: 24 }}>
            <FloatButton icon={<PlusOutlined/>} type="primary" tooltip={<div>Add Device</div>} onClick={() => {
                setModalOpen(true);
                setCredential({ id: null, type: 'device' });
            }}/>
            <FloatButton icon={<PlusOutlined/>} type="primary" tooltip={<div>Add Website</div>} onClick={() => {
                setModalOpen(true);
                setCredential({ id: null, type: 'website' });
            }}/>
        </FloatButton.Group>
    </>;

}