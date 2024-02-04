import { addShelf } from "../../../../api/shelves";
import React from "react";

export default function ShelfAddModal({ update }) {
    const path = React.useRef();
    const name = React.useRef();
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("shown.bs.modal", (e) => {
            path.current.focus();
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            path.current.value = '';
            name.current.value = '';
        });
    }, []);

    function confirm() {
        if (!path.current.value || !name.current.value) {
            return;
        }
        addShelf(path.current.value, name.current.value).then(
            res => update(res.data),
            err => console.error(err)
        );
    }

    return (
        <div className="modal fade" id="shelf-add-modal" tabIndex={-1} aria-hidden={true}
             aria-labelledby="shelf-add-modal-label" ref={modal}>
            <div className="modal-dialog">
                <div className="modal-content">
                    <div className="modal-header">
                        <h5 className="modal-title" id="shelf-add-modal-label">Create new shelf</h5>
                        <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                    </div>
                    <div className="modal-body">
                        <div className="mb-2">
                            <label htmlFor="add-path" className="form-label">Path</label>
                            <input ref={path} type="text" className="form-control" id="add-path"
                                   aria-describedby="add-path-help"/>
                            <div className="text-secondary">Insert a folder without the / at the end.</div>
                        </div>
                        <div className="mb-2">
                            <label htmlFor="add-name" className="form-label">Name</label>
                            <input ref={name} type="text" className="form-control" id="add-name"/>
                        </div>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-danger" data-bs-dismiss="modal">
                            Close
                        </button>
                        <button type="button" className="btn btn-primary" data-bs-dismiss="modal" onClick={confirm}>
                            Create
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}
