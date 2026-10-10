import FileManagerTreeSelect from "@CoreBundle/components/file-manager/FileManagerTreeSelect";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { useTracker } from "@AnimeBundle/components/TrackerContext";
import { CheckCircleOutlined, CloseCircleOutlined, FolderOpenOutlined, GlobalOutlined } from "@ant-design/icons";
import { Form, Input, List, Modal } from "antd";
import React from "react";

function DownloadedListItem({ download, fileExists }) {
    const icon = fileExists ? (
        <CheckCircleOutlined style={{ marginRight: 8, color: "#52c41a" }} />
    ) : (
        <CloseCircleOutlined style={{ marginRight: 8, color: "#ff4d4f" }} />
    );
    return <List.Item>{icon} {download.file}</List.Item>;
}

export default function SeasonFolderAddModal({ open, setOpen }) {
    const { tracker, trackerConfig } = useTracker();
    const [confirmLoading, setConfirmLoading] = React.useState(false);

    const [downloadedLoading, setDownloadedLoading] = React.useState(false);
    const [downloaded, setDownloaded] = React.useState([]);
    const [seriesId, setSeriesId] = React.useState(null);

    const [form] = Form.useForm();

    const api = useBackendApi();

    React.useEffect(() => {
        if (!seriesId) {
            setDownloaded([]);
            return;
        }
        setDownloadedLoading(true);
        api
            .withErrorHandling()
            .seasonFolders()
            .getDownloads(tracker, seriesId)
            .then(
                res => setDownloaded(res.data),
                () => setDownloaded([])
            )
            .finally(() => setDownloadedLoading(false));
    }, [seriesId, tracker]);

    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'season-folder-add-loader',
                loadingContent: 'Adding season folder...',
                successContent: 'Season folder added successfully',
            })
            .seasonFolders()
            .add(tracker, data)
            .then(() => setOpen(false))
            .finally(() => setConfirmLoading(false));
    }, [tracker]);

    return (
        <Modal
            open={open}
            title={<span>Add season folder ({trackerConfig.label})</span>}
            onCancel={() => setOpen(false)}
            afterClose={() => {
                setDownloaded([]);
                setSeriesId(null);
            }}
            destroyOnHidden
            okButtonProps={{ htmlType: 'submit' }}
            confirmLoading={confirmLoading}
            modalRender={(dom) => (
                <Form
                    form={form}
                    layout="vertical"
                    name="add_season_folder_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => {
                        const match = data.url.match(trackerConfig.animeUrlPattern);
                        if (match) {
                            data.id = Number.parseInt(match[1]);
                            delete data.url;
                            onSubmit(data);
                        }
                    }}
                    onValuesChange={(changedValues) => {
                        if (changedValues.url === undefined) return;
                        if (changedValues.url) {
                            const match = changedValues.url.match(trackerConfig.animeUrlPattern);
                            if (match) {
                                setSeriesId(Number.parseInt(match[1]));
                                return;
                            }
                        }
                        setSeriesId(null);
                    }}
                >
                    {dom}
                </Form>
            )}
        >
            <Form.Item
                label={`Url ${trackerConfig.label}`}
                name="url"
                rules={[
                    { required: true, message: "Please input season url." },
                    { pattern: trackerConfig.animeUrlPattern, message: `Invalid ${trackerConfig.label} url.` }
                ]}
            >
                <Input
                    prefix={<GlobalOutlined />}
                    placeholder={trackerConfig.animePlaceholder}
                    autoFocus
                />
            </Form.Item>
            <Form.Item
                label="Folder"
                name="folder"
                rules={[{ required: true, message: "Please input season folder." }]}
            >
                <FileManagerTreeSelect
                    apiUrl={api.fmUrl()}
                    prefix={<FolderOpenOutlined />}
                    placeholder="Folder"
                    showSearch
                    treeLine
                    style={{ width: "100%" }}
                    styles={{
                        popup: { root: { maxHeight: 400, overflow: "auto" } },
                    }}
                />
            </Form.Item>
            <List
                style={{ maxHeight: "50vh", overflowY: "scroll" }}
                size="small"
                bordered
                loading={downloadedLoading}
                dataSource={downloaded}
                renderItem={(item) => <DownloadedListItem download={item.download} fileExists={item.file_exists} />}
            />
        </Modal>
    );
}
