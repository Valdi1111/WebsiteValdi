import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import { faCopy, faDownload, faRotateLeft, faRotateRight } from "@fortawesome/free-solid-svg-icons";
import React from "react";

export default function ImageViewModal() {
    const [title, setTitle] = React.useState('Image from book');
    const [rotation, setRotation] = React.useState(0);
    const img = React.useRef();
    const input = React.useRef();
    const label = React.useRef();
    const modal = React.useRef();

    React.useEffect(() => {
        modal.current.addEventListener("show.bs.modal", (e) => {
            const target = e.relatedTarget;
            if (target.tagName.toLowerCase() === 'img') {
                setTitle(target.alt || 'Image from book');
                img.current.alt = target.alt;
                img.current.src = target.src;
            }
            if (target.tagName.toLowerCase() === 'image') {
                img.current.src = target.getAttribute('xlink:href');
            }
        });
        modal.current.addEventListener("hidden.bs.modal", (e) => {
            setTitle('Image from book');
            setRotation(0);
            input.current.value = 100;
            widthChange(input.current);
            img.current.alt = '';
            img.current.src = '';
        });
    }, []);

    React.useEffect(() => {
        img.current.style.transform = `rotate(${rotation}deg)`;
    }, [rotation]);

    function rotate(degree) {
        setRotation(rotation + degree);
    }

    async function copy() {
        console.debug("Copying image to clipboard...");
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        canvas.width = img.current.naturalWidth
        canvas.height = img.current.naturalHeight
        ctx.drawImage(img.current, 0, 0);
        canvas.toBlob(async blob => {
            await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
        }, 'image/png', 1);
    }

    async function download() {
        console.debug("Downloading image...");
        const res = await fetch(img.current.src);
        const blob = await res.blob();
        const element = document.createElement('a');
        element.href = URL.createObjectURL(blob);
        element.download = img.current.alt || 'image';
        element.click();
        console.debug("Image downloaded successfully!");
    }

    function widthChange(e) {
        img.current.style.width = e.value + '%';
        label.current.innerHTML = e.value + '%';
    }

    function wheelChange(e) {
        if (e.deltaY < 0) {
            e.target.stepUp();
        }
        if (e.deltaY > 0) {
            e.target.stepDown();
        }
        widthChange(e.target);
    }

    return <div className="modal fade" id="image-view-modal" tabIndex={-1} aria-hidden={true}
         aria-labelledby="image-view-modal-label" ref={modal}>
        <div className="modal-dialog modal-fullscreen">
            <div className="modal-content">
                <div className="modal-header">
                    <h5 className="modal-title text-truncate flex-grow-1" id="image-view-modal-label">
                        {title}
                    </h5>
                    <button type="button" className="btn-close" data-bs-dismiss="modal" aria-label="Close"/>
                </div>
                <div className="modal-body p-0">
                    <img ref={img} alt="" src="" width="100%"/>
                </div>
                <div className="modal-footer">
                    <button className="btn btn-icon btn-outline-secondary" onClick={copy}>
                        <FontAwesomeIcon icon={faCopy} width={16} height={16}/>
                    </button>
                    <button className="btn btn-icon btn-outline-secondary" onClick={download}>
                        <FontAwesomeIcon icon={faDownload} width={16} height={16}/>
                    </button>
                    <div className="d-flex flex-row">
                        <input ref={input} type="range" id="image-width" className="form-range flex-grow-1"
                               min={10} max={300} step={10} defaultValue={100} onChange={e => widthChange(e.target)}
                               onWheel={wheelChange}/>
                        <label ref={label} htmlFor="image-width" className="form-label text-center small mb-0 ps-2">
                            100%
                        </label>
                    </div>
                    <button className="btn btn-icon btn-outline-secondary" onClick={e => rotate(-90)}>
                        <FontAwesomeIcon icon={faRotateLeft} width={16} height={16}/>
                    </button>
                    <button className="btn btn-icon btn-outline-secondary" onClick={e => rotate(+90)}>
                        <FontAwesomeIcon icon={faRotateRight} width={16} height={16}/>
                    </button>
                </div>
            </div>
        </div>
    </div>;

}
