document.addEventListener('DOMContentLoaded', function() {
    const dataInput = document.getElementById('_click_map_data');
    if (!dataInput) return; // Not on the click map screen

    const svgCanvas = document.getElementById('click-map-svg');
    const mapList = document.getElementById('click-map-list');
    const finishBtn = document.getElementById('click-map-finish-shape');
    const cancelBtn = document.getElementById('click-map-cancel-shape');
    
    let mapsData = [];
    try {
        mapsData = JSON.parse(dataInput.value);
        if (!Array.isArray(mapsData)) mapsData = [];
    } catch (e) {
        mapsData = [];
    }

    let isDrawing = false;
    let currentPoints = [];
    let currentDrawingPolygon = null;

    const bgColorInput = document.getElementById('_click_map_bg_color');
    if (bgColorInput) {
        bgColorInput.addEventListener('input', function() {
            const polys = svgCanvas.querySelectorAll('.click-map-saved-poly');
            const val = this.value ? this.value + '80' : 'rgba(0, 100, 255, 0.4)';
            polys.forEach(p => p.setAttribute('fill', val));
        });
    }

    function generateId() {
        return Math.random().toString(36).substr(2, 9);
    }

    function renderMaps() {
        // Clear Existing DOM SVG Polygons
        svgCanvas.innerHTML = '';
        mapList.innerHTML = '';

        mapsData.forEach((map, index) => {
            // Render Polygon
            if (map.points) {
                const poly = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                poly.setAttribute("points", map.points);
                poly.setAttribute("class", "click-map-saved-poly");
                poly.setAttribute("stroke", "white");
                poly.setAttribute("stroke-width", "0.5");
                if (bgColorInput && bgColorInput.value) {
                    poly.setAttribute("fill", bgColorInput.value + "80");
                } else {
                    poly.setAttribute("fill", "rgba(0, 100, 255, 0.4)");
                }
                svgCanvas.appendChild(poly);
            }

            // Render List item
            const li = document.createElement('li');
            li.innerHTML = `
                <div class="map-item-header">
                    <strong>Map #${index + 1}</strong>
                    <button type="button" class="button button-small delete-map" data-index="${index}">Delete</button>
                </div>
                <div class="map-item-gallery">
                    <p>Attached Images: ${map.gallery.length}</p>
                    <div class="gallery-thumbnails">
                        ${map.gallery.map(img => `<img src="${img.url}" width="50" style="margin-right: 5px;" />`).join('')}
                    </div>
                    <button type="button" class="button button-secondary select-gallery" data-index="${index}">Manage Gallery</button>
                </div>
            `;
            mapList.appendChild(li);
        });

        updateInput();
    }

    function updateInput() {
        dataInput.value = JSON.stringify(mapsData);
    }

    // Drawing Logic
    svgCanvas.addEventListener('click', function(e) {
        // Only draw if we left-clicked
        if (e.button !== 0) return;

        const rect = svgCanvas.getBoundingClientRect();
        const x = ((e.clientX - rect.left) / rect.width) * 100;
        const y = ((e.clientY - rect.top) / rect.height) * 100;

        if (!isDrawing) {
            isDrawing = true;
            currentPoints = [];
            finishBtn.disabled = false;
            cancelBtn.disabled = false;
            
            currentDrawingPolygon = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
            currentDrawingPolygon.setAttribute("class", "click-map-drawing-poly");
            currentDrawingPolygon.setAttribute("fill", "rgba(255, 0, 0, 0.4)");
            currentDrawingPolygon.setAttribute("stroke", "red");
            currentDrawingPolygon.setAttribute("stroke-width", "0.5");
            svgCanvas.appendChild(currentDrawingPolygon);
        }

        currentPoints.push(`${x.toFixed(2)},${y.toFixed(2)}`);
        currentDrawingPolygon.setAttribute("points", currentPoints.join(" "));
    });

    // Double click to finish
    svgCanvas.addEventListener('dblclick', function(e) {
        if (isDrawing && currentPoints.length > 2) {
            // Remove the last point from double click
            currentPoints.pop(); 
            finishShape();
        }
    });

    function finishShape() {
        if (!isDrawing || currentPoints.length < 3) {
            alert('A polygon needs at least 3 points.');
            return;
        }

        const newMap = {
            id: generateId(),
            points: currentPoints.join(" "),
            gallery: []
        };
        mapsData.push(newMap);

        resetDrawing();
        renderMaps();
    }

    function resetDrawing() {
        isDrawing = false;
        currentPoints = [];
        if (currentDrawingPolygon && currentDrawingPolygon.parentNode) {
            currentDrawingPolygon.parentNode.removeChild(currentDrawingPolygon);
        }
        currentDrawingPolygon = null;
        finishBtn.disabled = true;
        cancelBtn.disabled = true;
    }

    finishBtn.addEventListener('click', finishShape);
    cancelBtn.addEventListener('click', function() {
        resetDrawing();
    });

    // Manage Gallery & Delete Map
    mapList.addEventListener('click', function(e) {
        if (e.target.classList.contains('delete-map')) {
            const index = e.target.getAttribute('data-index');
            if (confirm('Are you sure you want to delete this map?')) {
                mapsData.splice(index, 1);
                renderMaps();
            }
        }

        if (e.target.classList.contains('select-gallery')) {
            const index = e.target.getAttribute('data-index');
            openGalleryFrame(index);
        }
    });

    // wp.media Frame
    let mediaFrame;
    function openGalleryFrame(index) {
        if (mediaFrame) {
            mediaFrame.off('select');
        }

        mediaFrame = wp.media({
            title: 'Select Images for Gallery',
            button: {
                text: 'Use these images'
            },
            multiple: true
        });

        mediaFrame.on('select', function() {
            var selection = mediaFrame.state().get('selection');
            var selectedImages = [];
            selection.map(function(attachment) {
                attachment = attachment.toJSON();
                selectedImages.push({
                    id: attachment.id,
                    url: attachment.url
                });
            });

            mapsData[index].gallery = selectedImages;
            renderMaps();
        });

        mediaFrame.open();
    }

    // Initial render
    renderMaps();
});
