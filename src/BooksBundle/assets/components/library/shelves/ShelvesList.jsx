import ShelfAddModal from "@BooksBundle/components/library/shelves/ShelfAddModal";
import { useShelves } from "@BooksBundle/components/library/shelves/ShelvesContext";
import { PlusOutlined } from "@ant-design/icons";
import { FloatButton, Menu } from "antd";
import { Link } from "react-router";
import React from "react";

export default function ShelvesList() {
    const [addOpen, setAddOpen] = React.useState(false);
    const { shelves, selectedShelf } = useShelves();

    const items = React.useMemo(() => {
        return shelves.map(s => ({
            key: s.id.toString(),
            label: <Link to={`/library/shelves/${s.id}`}>{s.name}</Link>,
        }));
    }, [shelves]);

    return <>
        <ShelfAddModal visible={addOpen} setVisible={setAddOpen}/>
        <Menu
            selectedKeys={selectedShelf ? [selectedShelf.id.toString()] : []}
            style={{ flex: 1, minWidth: 0 }}
            items={items}
        />
        <FloatButton icon={<PlusOutlined/>} tooltip={'Add shelf'} onClick={() => setAddOpen(true)}/>
    </>;
}
