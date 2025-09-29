import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { App, Dropdown, Form, Input, Modal } from "antd";
import {
    CopyOutlined,
    DeleteOutlined,
    DownloadOutlined,
    EditOutlined,
    ExclamationCircleFilled,
    ScissorOutlined,
    SnippetsOutlined
} from "@ant-design/icons";
import React from "react";

export default function FilesTableRowDropdown({ children, rowKey }) {
    const { api, selectedId, files, reloadFiles, clipboard, setClipboard } = useFileManager();
    const [visibleRename, setVisibleRename] = React.useState(false);
    const [confirmLoadingRename, setConfirmLoadingRename] = React.useState(false);
    const { message, modal } = App.useApp();
    const [formRename] = Form.useForm();

    const items = React.useMemo(() => {
        const pasteItem = {
            key: 'paste',
            label: 'Paste',
            icon: <SnippetsOutlined/>,
            extra: 'Ctrl+V',
            onClick: () => onPaste(),
            disabled: !clipboard,
        };
        if (!rowKey) {
            return [pasteItem];
        }
        return [
            {
                key: 'download',
                label: 'Download',
                icon: <DownloadOutlined/>,
                extra: 'Ctrl+D',
                onClick: () => onDownload(),
            },
            {
                type: 'divider',
            },
            {
                key: 'copy',
                label: 'Copy',
                icon: <CopyOutlined/>,
                extra: 'Ctrl+C',
                onClick: () => onCopy(),
            },
            {
                key: 'cut',
                label: 'Cut',
                icon: <ScissorOutlined/>,
                extra: 'Ctrl+X',
                onClick: () => onCut(),
            },
            pasteItem,
            {
                type: 'divider',
            },
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
        ]
    }, [rowKey]);

    const onDownload = React.useCallback(() => {
        console.debug("Downloading", rowKey);
        api.fmDownload(rowKey);
    }, [rowKey]);

    const onCopy = React.useCallback(() => {
        console.debug("Copying", rowKey);
        setClipboard({
            action: 'copy',
            id: rowKey,
        });
    }, [rowKey]);

    const handleCopy = React.useCallback(id => {
        message.open({
            key: 'file-copy-loader',
            type: 'loading',
            content: 'Copying file...',
            duration: 0,
        });
        api.fmCopy(id, selectedId).then(
            res => {
                message.open({
                    key: 'file-copy-loader',
                    type: 'success',
                    content: 'File copied successfully',
                    duration: 2.5,
                });
                reloadFiles();
                setVisibleRename(false);
            },
            err => {
                message.destroy('file-copy-loader');
                console.error(err);
            }
        );
    }, [selectedId]);

    const onCut = React.useCallback(() => {
        console.debug("Cutting", rowKey);
        setClipboard({
            action: 'cut',
            id: rowKey,
        });
    }, [rowKey]);

    const handleMove = React.useCallback(id => {
        message.open({
            key: 'file-move-loader',
            type: 'loading',
            content: 'Moving file...',
            duration: 0,
        });
        api.fmMove(id, selectedId).then(
            res => {
                message.open({
                    key: 'file-move-loader',
                    type: 'success',
                    content: 'File moved successfully',
                    duration: 2.5,
                });
                reloadFiles();
                setClipboard(null);
                setVisibleRename(false);
            },
            err => {
                message.destroy('file-move-loader');
                console.error(err);
            }
        );
    }, [selectedId]);

    const onPaste = React.useCallback(() => {
        console.debug("Pasting", clipboard);
        if (!clipboard) {
            return;
        }
        if (clipboard.action === 'copy') {
            handleCopy(clipboard.id);
        }
        if (clipboard.action === 'cut') {
            handleMove(clipboard.id);
        }
    }, []);

    const onRename = React.useCallback(() => {
        console.debug("Renaming", rowKey);
        const row = files.find(f => f.key === rowKey);
        formRename.setFieldValue("name", row?.title);
        setVisibleRename(true);

    }, [rowKey, files]);

    const handleRename = React.useCallback(data => {
        setConfirmLoadingRename(true);
        message.open({
            key: 'file-rename-loader',
            type: 'loading',
            content: 'Renaming file...',
            duration: 0,
        });
        api.fmRename(rowKey, data.name).then(
            res => {
                message.open({
                    key: 'file-rename-loader',
                    type: 'success',
                    content: 'File renamed successfully',
                    duration: 2.5,
                });
                reloadFiles();
                setVisibleRename(false);
            },
            err => {
                message.destroy('file-rename-loader');
                console.error(err);
            }
        ).finally(() => {
            setConfirmLoadingRename(false);
        });
    }, [rowKey]);

    const onDelete = React.useCallback(() => {
        console.debug("Delete", rowKey);
        const row = files.find(f => f.key === rowKey);
        const backendFunction = row?.type === 'folder' ? api.fmDeleteDir : api.fmDeleteFile;
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Delete files',
            content: <div>
                <h6>Are you sure you want to delete this item:</h6>
                <ul>
                    <li>{rowKey}</li>
                </ul>
            </div>,
            onOk() {
                message.open({
                    key: 'file-delete-loader',
                    type: 'loading',
                    content: 'Deleting files...',
                    duration: 0,
                });
                return backendFunction(rowKey).then(
                    res => {
                        message.open({
                            key: 'file-delete-loader',
                            type: 'success',
                            content: 'Files deleted successfully',
                            duration: 2.5,
                        });
                        reloadFiles();
                    },
                    err => {
                        message.destroy('file-delete-loader');
                        console.error(err);
                    }
                );
            },
        });
    }, [rowKey, files]);

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
                    name="form_in_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => handleRename(data)}>
                    {dom}
                </Form>
            }
        >
            <Form.Item name="name" rules={[
                {
                    required: true,
                    message: `Please input the file name!`,
                },
            ]}>
                <Input/>
            </Form.Item>
        </Modal>
        <Dropdown
            menu={{ items }}
            trigger={['contextMenu']}
            destroyOnHidden
        >
            {children}
        </Dropdown>
    </>;
}