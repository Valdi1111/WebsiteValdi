import { Button, Checkbox, Flex, Form, Input, Modal, Space, Table, Tag } from "antd";
import { formatDateFromIso, formatDateTimeFromIso } from "@CoreBundle/utils";
import { SearchOutlined } from "@ant-design/icons";
import Highlighter from "react-highlight-words";
import React from "react";

/**
 * @typedef {Object} TableState
 * @property {boolean} structure_state
 * @property {boolean} rows_state
 * @property {Array} columns
 * @property {Array} rows
 * @property {TablePagination} [pagination]
 * @property {TableSorter} [sorter]
 * @property {TableFilters} [filters]
 */

/**
 * @typedef {Object} TableAction
 * @property {string} type
 * @property {any} [payload]
 */

/**
 * @typedef {Object} TableSorter
 * @property {string} field
 * @property {string} order
 */

/**
 * @typedef {Object} TablePagination
 * @property {int} current
 * @property {int} pageSize
 * @property {int} total
 */

/**
 * @typedef {Object} TableFilters
 */

/**
 * @param {TableState} state
 * @param {string} type
 * @param {any} payload
 * @returns {TableState}
 */
function reducer(state, { type, payload }) {
    switch (type) {
        case "structure_loaded":
            return {
                ...state,
                structure_state: "loaded",
                rows: payload.rows,
                columns: payload.columns,
                pagination: {
                    ...state.pagination,
                    ...payload.defaultParameters.pagination,
                    total: payload.count,
                },
                sorter: payload.defaultParameters.sorter,
            };
        case "structure_ready":
            return {
                ...state,
                structure_state: "ready",
                rows_state: "ready",
            };
        case "rows_loading":
            return {
                ...state,
                rows_state: "loading",
            };
        case "rows_ready":
            return {
                ...state,
                pagination: {
                    ...state.pagination,
                    total: payload.count,
                },
                rows: payload.rows,
                rows_state: "ready",
            };
        case "update_settings":
            let rows = state.rows;
            // `dataSource` is useless since `pageSize` changed
            if (payload.pagination.pageSize !== state.pagination?.pageSize) {
                rows = [];
            }
            return {
                ...state,
                pagination: {
                    ...state.pagination,
                    ...payload.pagination,
                },
                filters: payload.filters,
                sorter: payload.sorter,
                rows,
            };
        case "update_visible_columns":
            return {
                ...state,
                columns: state.columns.map(c => ({ ...c, hidden: !payload[c.dataIndex] })),
            };
        default:
            return state;
    }
}

/**
 * @type {TableState}
 */
const INITIAL_STATE = {
    structure_state: "loading",
    rows_state: "loading",
    columns: [],
    rows: [],
    pagination: {
        showTotal: (total, range) => <div>{range.join("-")} of {total} items</div>,
    }
};

export default function StandardTable({
                                          backendFunction,
                                          isVisibilityColumnsOpen = false,
                                          setVisibilityColumnsOpen = null,
                                          tableStyle = {},
                                          tableOnRow = null,
                                          tableOnHeaderRow = null,
                                          tableOnCell = null,
                                          tableOnHeaderCell = null,
                                          extraColumnsBefore = [],
                                          extraColumnsAfter = []
                                      }) {
    const [table, dispatch] = React.useReducer(reducer, INITIAL_STATE);
    const [form] = Form.useForm();

    React.useEffect(() => {
        if (table.structure_state === "loading") {
            backendFunction({ initializing: true }).then(
                res => {
                    const payload = res.data;
                    payload.columns.unshift(...extraColumnsBefore);
                    payload.columns.push(...extraColumnsAfter);
                    dispatch({ type: "structure_loaded", payload });
                },
            );
            return;
        }
        if (table.structure_state === "loaded") {
            dispatch({ type: "structure_ready" });
            return;
        }
        fetchData();
    }, [
        table.pagination?.current,
        table.pagination?.pageSize,
        table.sorter?.field,
        table.sorter?.order,
        JSON.stringify(table.filters),
    ]);

    function fetchData() {
        if (table.structure_state !== "ready") {
            return;
        }
        dispatch({ type: "rows_loading" });
        backendFunction({
            pagination: {
                pageSize: table.pagination.pageSize,
                current: table.pagination.current,
            },
            filters: table.filters,
            sorter: table.sorter,
        }).then(
            res => {
                dispatch({ type: "rows_ready", payload: res.data });
            },
        );
    }

    function handleTableChange(pagination, filters, sorter, extra) {
        dispatch({
            type: "update_settings",
            payload: {
                pagination,
                filters,
                sorter: Array.isArray(sorter) ? undefined : { field: sorter.field, order: sorter.order },
            }
        });
    }

    /**
     * Inizio filtro testo
     */
    const [searchText, setSearchText] = React.useState({});
    const searchInput = React.useRef(null);

    const handleSearch = (dataIndex, confirm, selectedKey, closeDropdown = true) => {
        confirm({ closeDropdown });
        setSearchText(t => {
            t[dataIndex] = selectedKey;
            return t;
        });
    };

    const handleReset = (dataIndex, clearFilters) => {
        clearFilters();
        setSearchText(t => {
            t[dataIndex] = '';
            return t;
        });
    };

    const getColumnSearchProps = c => ({
        filterDropdown: ({ setSelectedKeys, selectedKeys, confirm, clearFilters, close }) => (
            <div style={{ padding: 8 }} onKeyDown={e => e.stopPropagation()}>
                <Input
                    ref={searchInput}
                    placeholder={`Search ${c.title}`}
                    value={selectedKeys[0]}
                    onChange={e => setSelectedKeys(e.target.value ? [e.target.value] : [])}
                    onPressEnter={() => handleSearch(c.dataIndex, confirm, selectedKeys[0])}
                    style={{ marginBottom: 8, display: 'block' }}
                />
                <Space>
                    <Button type="primary" icon={<SearchOutlined/>} size="small" style={{ width: 90 }} onClick={
                        () => handleSearch(c.dataIndex, confirm, selectedKeys[0])
                    }>Search</Button>
                    <Button size="small" style={{ width: 90 }} onClick={
                        () => clearFilters && handleReset(c.dataIndex, clearFilters)
                    }>Reset</Button>
                    <Button type="link" size="small" onClick={
                        () => handleSearch(c.dataIndex, confirm, selectedKeys[0], false)
                    }>Filter</Button>
                    <Button type="link" size="small" onClick={
                        () => close()
                    }>Close</Button>
                </Space>
            </div>
        ),
        filterIcon: filtered => <SearchOutlined style={{ color: filtered ? '#1677ff' : undefined }}/>,
        // TODO abilitarlo se è a dati offline
        // onFilter: (value, record) => record[dataIndex].toString().toLowerCase().includes(value.toLowerCase()),
        filterDropdownProps: {
            onOpenChange(open) {
                if (!open) {
                    return;
                }
                setTimeout(() => searchInput.current?.select(), 100);
            },
        },
        // TODO da spostare in onCell
        render: text => <Highlighter
            highlightStyle={{ backgroundColor: '#ffc069', padding: 0 }}
            searchWords={[searchText[c.dataIndex]]}
            autoEscape
            textToHighlight={text ? text.toString() : ''}
        />,
    });

    return <>
        <Modal
            open={isVisibilityColumnsOpen}
            title={'Columns displayed'}
            onCancel={() => setVisibilityColumnsOpen && setVisibilityColumnsOpen(false)}
            destroyOnHidden
            okButtonProps={{ htmlType: 'submit' }}
            modalRender={(dom) =>
                <Form
                    form={form}
                    layout="vertical"
                    name="form_in_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => dispatch({ type: "update_visible_columns", payload: data })}>
                    {dom}
                </Form>
            }
            styles={{ body: { overflowY: 'auto', maxHeight: '85vh' } }}
            centered
        >
            <Flex vertical>
                {table.columns.map(c => (
                    <Form.Item
                        key={c.dataIndex}
                        name={c.dataIndex}
                        valuePropName="checked"
                        initialValue={!c.hidden}
                        noStyle
                    >
                        <Checkbox>{c.title}</Checkbox>
                    </Form.Item>
                ))}
            </Flex>
        </Modal>
        {table.structure_state === "ready" && <Table
            columns={table.columns.map(c => {
                if (c.valueFormat === "datetime") {
                    return {
                        ...c,
                        render: formatDateTimeFromIso,
                    };
                }
                if (c.valueFormat === "date") {
                    return {
                        ...c,
                        render: formatDateFromIso,
                    };
                }
                if (c.valueFormat === "tags") {
                    // TODO convertire i tags in oggetti compressi con varie prop (color, value, etc)
                    //  se invece è un array normale come ora, niente colore e niente prop
                    return {
                        ...c,
                        render: tags => {
                            if (!tags) {
                                return <></>;
                            }
                            return <>
                                {tags.map((tag) => {
                                    let color = tag.length > 5 ? 'geekblue' : 'green';
                                    return <Tag color={color} key={tag}>
                                        {tag.toUpperCase()}
                                    </Tag>;
                                })}
                            </>
                        },
                    };
                }
                if (c.filterType === "string") {
                    return {
                        ...c,
                        ...getColumnSearchProps(c)
                    };
                }
                return c;
            })}
            rowKey={(record) => record.id}
            dataSource={table.rows}
            pagination={table.pagination}
            loading={table.rows_state === "loading"}
            onChange={handleTableChange}
            scroll={{ x: 'max-content' }}
            style={tableStyle}
            onRow={tableOnRow}
            onHeaderRow={tableOnHeaderRow}
            onCell={tableOnCell}
            onHeaderCell={tableOnHeaderCell}
        />}
    </>;

}