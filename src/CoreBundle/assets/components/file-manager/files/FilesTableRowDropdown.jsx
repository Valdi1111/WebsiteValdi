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

export default function FilesTableRowDropdown({ children, row }) {
    const [visibleRename, setVisibleRename] = React.useState(false);
    const [confirmLoadingRename, setConfirmLoadingRename] = React.useState(false);
    const [formRename] = Form.useForm();
    const { modal } = App.useApp();

    const {
        api,
        selectedFolder,
        setSelectedFile,
        reloadFolders,
        reloadFiles,
        clipboard,
        setClipboard
    } = useFileManager();

    const items = React.useMemo(() => {
        const pasteItem = {
            key: 'paste',
            label: 'Paste',
            icon: <SnippetsOutlined/>,
            extra: 'Ctrl+V',
            onClick: () => onPaste(),
            disabled: !clipboard,
        };
        if (!row) {
            return [pasteItem];
        }
        const out = [];
        if (row.type !== 'folder') {
            out.push(
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
            );
        }
        out.push(
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
        );
        return out;
    }, [row]);

    const onDownload = React.useCallback(() => {
        if (!row) {
            return;
        }
        console.debug("Downloading", row.id);
        api
            .withErrorHandling()
            .fmDownload(row.id);
    }, [row]);

    const onCopy = React.useCallback(() => {
        if (!row) {
            return;
        }
        console.debug("Copying", row.id);
        setClipboard({
            action: 'copy',
            item: row,
        });
    }, [row]);

    const handleCopy = React.useCallback(id => {
        api
            .withLoadingMessage({
                key: 'file-copy-loader',
                loadingContent: 'Copying file...',
                successContent: 'File copied successfully',
            })
            .fmCopy(id, selectedFolder.id)
            .then(res => {
                setClipboard(null);
                reloadFiles().then(() => setSelectedFile(res.data));
                setVisibleRename(false);
            });
    }, [selectedFolder?.id]);

    const onCut = React.useCallback(() => {
        if (!row) {
            return;
        }
        console.debug("Cutting", row.id);
        setClipboard({
            action: 'cut',
            item: row,
        });
    }, [row]);

    const handleMove = React.useCallback(id => {
        api
            .withLoadingMessage({
                key: 'file-move-loader',
                loadingContent: 'Moving file...',
                successContent: 'File moved successfully',
            })
            .fmMove(id, selectedFolder.id)
            .then(res => {
                setClipboard(null);
                reloadFiles().then(() => setSelectedFile(res.data));
                setVisibleRename(false);
            });
    }, [selectedFolder?.id]);

    const onPaste = React.useCallback(() => {
        if (!clipboard) {
            return;
        }
        console.debug("Pasting", clipboard);
        if (clipboard.action === 'copy') {
            handleCopy(clipboard.item.id);
        }
        if (clipboard.action === 'cut') {
            handleMove(clipboard.item.id);
        }
    }, [clipboard]);

    const onRename = React.useCallback(() => {
        if (!row) {
            return;
        }
        console.debug("Renaming", row.id);
        formRename.setFieldValue("name", row.title);
        setVisibleRename(true);

    }, [row]);

    const handleRename = React.useCallback((data) => {
        if (!row) {
            return;
        }
        const backendFunction = row.type === 'folder' ? 'fmRenameFolder' : 'fmRenameFile';
        setConfirmLoadingRename(true);
        api
            .withLoadingMessage({
                key: 'file-rename-loader',
                loadingContent: 'Renaming file...',
                successContent: 'File renamed successfully',
            })
            [backendFunction](row.id, data.name)
            .then(res => {
                if (row.type === 'folder') {
                    reloadFolders();
                    reloadFiles();
                } else {
                    reloadFiles().then(() => setSelectedFile(res.data));
                }
                setVisibleRename(false);
            })
            .finally(() => {
                setConfirmLoadingRename(false);
            });
    }, [row]);

    const onDelete = React.useCallback(() => {
        if (!row) {
            return;
        }
        console.debug("Delete", row.id);
        const backendFunction = row?.type === 'folder' ? 'fmDeleteFolder' : 'fmDeleteFile';
        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: 'Are you sure you want to delete this files?',
            content: <ul>
                <li>{row.title}</li>
            </ul>,
            onOk: () => api
                .withLoadingMessage({
                    key: 'file-delete-loader',
                    loadingContent: 'Deleting files...',
                    successContent: 'Files deleted successfully',
                })
                [backendFunction](row.id)
                .then(res => {
                    if (row.type === 'folder') {
                        reloadFolders();
                        reloadFiles();
                    } else {
                        reloadFiles().then(() => setSelectedFile(res.data));
                    }
                }),
        });
    }, [row]);

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
                    name="file_rename_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => handleRename(data)}>
                    {dom}
                </Form>
            }
        >
            <Form.Item name="name" rules={[
                {
                    required: true,
                    message: 'Please input the file name!',
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