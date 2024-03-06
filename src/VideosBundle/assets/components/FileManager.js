import MainLayout from "@VideosBundle/components/MainLayout";
import React from "react";

export default function FileManager() {
    const divRef = React.useRef();
    React.useEffect(() => {
        webix.ready(function () {
            // use custom scrolls, optional
            webix.CustomScroll.init();

            const app = new fileManager.App({
                url: "/api/fileManager/",
                override: new Map([
                    [fileManager.services.Backend, Backend],
                    [fileManager.services.LocalData, LocalData],
                    [fileManager.services.Operations, Operations],
                    [fileManager.views.cards, Cards],
                    [fileManager.views.list, List],
                    [fileManager.views.folders, Folders],
                ]),
            });
            app.render(divRef.current);
        });
    }, []);

    return (
        <MainLayout>
            <div ref={divRef} className="flex-grow-1"></div>
        </MainLayout>
    );

}

function sortFiles(data, dir) {
    const nDir = dir === "asc" ? 1 : -1;
    //complex sorting by value while excluding "back to parent" label
    data.sort(function (a, b) {
        if (
            a.value === ".." ||
            (a.type === "folder" && b.type !== "folder")
        )
            return -1 * nDir;
        if (
            b.value === ".." ||
            (b.type === "folder" && a.type !== "folder")
        )
            return 1 * nDir;
        return a.value < b.value ? -1 : a.value > b.value ? 1 : 0;
    }, dir);

    this.$$("table").markSorting("value", dir);
}

class Backend extends fileManager.services.Backend {

    openLink(id, download) {
        return `videos?path=${encodeURIComponent(id)}`
    }

}

class LocalData extends fileManager.services.LocalData {

    defaultTree() {
        return [{ value: "Videos", id: "../files", open: true }];
    }

}

class Operations extends fileManager.services.Operations {

    /**
     * Opens files in new browser tabs
     * @param {Array} files - an array of file data objects
     */
    open(files) {
        if (!files) files = this.state.selectedItem;
        for (let i = 0; i < files.length; ++i) {
            if (files[i].type === "video") {
                window.open(this.backend().openLink(files[i].id), "_blank");
            } else if (files[i].type !== "folder") {
                window.open(this.backend().directLink(files[i].id), "_blank");
            }
        }
    }

}

class Cards extends fileManager.views.cards {

    RenderData(data) {
        super.RenderData(data);
        // wait until data is returned from backend/cache, then sort it
        data.waitData.then(() => sortFiles.call(this, data, "asc"));
    }

}

class List extends fileManager.views.list {

    RenderData(data) {
        super.RenderData(data);
        // wait until data is returned from backend/cache, then sort it
        data.waitData.then(() => sortFiles.call(this, data, "asc"));
    }

}

class Folders extends fileManager.views.folders {

    GetFsStats(force) {
        super.GetFsStats(force);
        this.Tree.sort("#value#", "asc", "string");
    }

}