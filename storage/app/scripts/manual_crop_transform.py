
import cv2
import numpy as np
import sys
import json
import os
import traceback

def parse_points(points_str):
    try:
        points = []
        pairs = points_str.split(',')
        for pair in pairs:
            x, y = map(float, pair.split('_'))
            points.append([x, y])
        if len(points) != 4:
            raise ValueError("Se esperaban exactamente 4 puntos")
        return np.array(points, dtype='float32')
    except Exception as e:
        raise ValueError("Formato de puntos inválido: " + str(e))

def crop_and_warp(input_path, output_path, points):
    # Ordenar los puntos
    def order_points(pts):
        rect = np.zeros((4, 2), dtype="float32")
        s = pts.sum(axis=1)
        rect[0] = pts[np.argmin(s)]
        rect[2] = pts[np.argmax(s)]
        diff = np.diff(pts, axis=1)
        rect[1] = pts[np.argmin(diff)]
        rect[3] = pts[np.argmax(diff)]
        return rect

    pts = order_points(points)

    width = int(max(np.linalg.norm(pts[1] - pts[0]), np.linalg.norm(pts[2] - pts[3])))
    height = int(max(np.linalg.norm(pts[3] - pts[0]), np.linalg.norm(pts[2] - pts[1])))

    dst = np.array([[0, 0], [width-1, 0], [width-1, height-1], [0, height-1]], dtype="float32")
    M = cv2.getPerspectiveTransform(pts, dst)

    img = cv2.imread(input_path)
    if img is None:
        raise Exception("No se pudo cargar la imagen")

    warped = cv2.warpPerspective(img, M, (width, height))

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    cv2.imwrite(output_path, warped)

    result = {
        "ok": True,
        "width": width,
        "height": height
    }

    print(json.dumps(result, ensure_ascii=True))

if __name__ == "__main__":
    try:
        if len(sys.argv) != 4:
            raise Exception("Uso: script.py input_path output_path 'x1_y1,x2_y2,x3_y3,x4_y4'")
        input_path = sys.argv[1]
        output_path = sys.argv[2]
        points = parse_points(sys.argv[3])
        crop_and_warp(input_path, output_path, points)
    except Exception as e:
        print(json.dumps({"error": str(e)}))
        traceback.print_exc()
        sys.exit(1)
