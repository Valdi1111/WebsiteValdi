import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { DeleteOutlined, EditOutlined, ExclamationCircleFilled, } from "@ant-design/icons";
import { App, Dropdown, Form, Input, Modal } from "antd";
import React from "react";

export default function FoldersTreeDropdown({ children, node, posX, posY, open, onOpenChange }) {
    const [visibleRename, setVisibleRename] = React.useState(false);
    const [confirmLoadingRename, setConfirmLoadingRename] = React.useState(false);
    const [formRename] = Form.useForm();
    const { modal } = App.useApp();

    const { api, reloadFolders, setSelectedFolder } = useFileManager();

    const items = React.useMemo(() => [
        {
            key: 'rename',
            label: 'Rename',
            icon: <EditOutlined/>,
            extra: 'Ctrl+R',
            onClick: () => onRename(),
        },
        {
            key: 'delete',
            label: 'Delete',
            icon: <DeleteOutlined/>,
            extra: 'Del / ←',
            onClick: () => onDelete(),
        },
    ], [node?.id]);

    const onRename = React.useCallback(() => {
        if (!node) {
            return;
        }
        console.debug("Renaming", node.id);
        formRename.setFieldValue("name", node.title);
        setVisibleRename(true);
    }, [node?.id]);

    const handleRename = React.useCallback(data => {
        if (!node) {
            return;
        }
        setConfirmLoadingRename(true);
        api
            .withLoadingMessage({
                key: 'folder-rename-loader',
                loadingContent: 'Renaming folder...',
                successContent: 'Folder renamed successfully',
            })
            .fmRenameFolder(node.id, data.name)
            .then(res => {
                reloadFolders().then(() => setSelectedFolder(res.data));
                setVisibleRename(false);
            })
            .finally(() => {
                setConfirmLoadingRename(false);
            });
    }, [node?.id]);

    const onDelete = React.useCallback(() => {
        if (!node) {
            return;
        }
        console.debug("Delete", node.id);
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this folders?',
            content: <ul>
                <li>{node.title}</li>
            </ul>,
            onOk: () => api
                .withLoadingMessage({
                    key: 'folder-delete-loader',
                    loadingContent: 'Deleting folders...',
                    successContent: 'Folders deleted successfully',
                })
                .fmDeleteFolder(node.id)
                .then(res => {
                    reloadFolders().then(t => setSelectedFolder(t[0]));
                }),
        });
    }, [node?.id]);

    return <>
        <Modal
            open={visibleRename}
            title={<span>Enter a new name</span>}
            onCancel={() => setVisibleRename(false)}
            destroyOnHidden
            okButtonProps={{
                autoFocus: true,
                htmlType: 'submit',
            }}
            confirmLoading={confirmLoadingRename}
            modalRender={(dom) =>
                <Form
                    form={formRename}
                    layout="vertical"
                    name="folder_rename_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => handleRename(data)}>
                    {dom}
                </Form>
            }
        >
            <Form.Item name="name" rules={[
                {
                    required: true,
                    message: 'Please input the folder name!',
                },
            ]}>
                <Input/>
            </Form.Item>
        </Modal>
        <Dropdown
            overlayStyle={{ left: `${posX}px`, top: `${posY}px` }}
            open={open}
            onOpenChange={onOpenChange}
            menu={{ items }}
            trigger={['contextMenu']}
            destroyOnHidden
        >
            {children}
        </Dropdown>
    </>;
}