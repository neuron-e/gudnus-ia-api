import cv2
import numpy as np
import sys
import json
import traceback
import os
import argparse

def calcular_integridad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    non_black = np.count_nonzero(gray > 30)
    total = gray.size
    return round((non_black / total) * 100, 2)

def calcular_luminosidad(img):
    hsv = cv2.cvtColor(img, cv2.COLOR_BGR2HSV)
    brightness = hsv[:, :, 2]
    return round(np.mean(brightness), 5)

def calcular_uniformidad(img):
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    return round(np.std(gray), 3)

def es_imagen_totalmente_inutilizable(img):
    """
    Detecta si una imagen es literalmente totalmente negra o blanca.
    """
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    mean_val = np.mean(gray)
    std_val = np.std(gray)

    # Si es casi totalmente negra (media muy baja, desviación muy baja)
    if mean_val < 5 and std_val < 3:
        return True, "Imagen totalmente negra o inutilizable"

    # Si es casi totalmente blanca (media muy alta, desviación muy baja)
    if mean_val > 250 and std_val < 3:
        return True, "Imagen totalmente blanca o sobreexpuesta"

    return False, "Imagen procesable"

def order_points(pts):
    rect = np.zeros((4, 2), dtype="float32")
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]
    rect[2] = pts[np.argmax(s)]
    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]
    rect[3] = pts[np.argmax(diff)]
    return rect

def process_image(input_path, output_path, filas=10, columnas=6):
    img = cv2.imread(input_path)
    if img is None:
        raise Exception(f"No se pudo cargar la imagen: {input_path}")

    # Verificar solo si la imagen es totalmente inutilizable
    es_inutilizable, mensaje = es_imagen_totalmente_inutilizable(img)
    if es_inutilizable:
        raise Exception(f"Imagen no procesable: {mensaje}")

    # Métricas iniciales
    integridad = calcular_integridad(img)
    luminosidad = calcular_luminosidad(img)
    uniformidad = calcular_uniformidad(img)

    # Preprocesamiento para contornos
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    thresh = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                   cv2.THRESH_BINARY, 11, 2)
    thresh = cv2.bitwise_not(thresh)
    thresh = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))

    contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        raise Exception("No se detectaron contornos en la imagen")

    panel_contour = max(contours, key=cv2.contourArea)
    epsilon = 0.02 * cv2.arcLength(panel_contour, True)
    approx = cv2.approxPolyDP(panel_contour, epsilon, True)

    if len(approx) > 4:
        box = cv2.boxPoints(cv2.minAreaRect(approx)).astype(np.int32)  # Cambiado de np.int0 a np.int32
        approx = box.reshape(-1, 1, 2)
    elif len(approx) < 4:
        x, y, w, h = cv2.boundingRect(panel_contour)
        approx = np.array([[[x, y]], [[x+w, y]], [[x+w, y+h]], [[x, y+h]]])

    pts = order_points(approx.reshape(len(approx), 2))

    width = int(max(np.linalg.norm(pts[1] - pts[0]), np.linalg.norm(pts[2] - pts[3])))
    height = int(max(np.linalg.norm(pts[3] - pts[0]), np.linalg.norm(pts[2] - pts[1])))

    if width / height < 0.5:
        width = int(height * 0.5)
    elif width / height > 2.0:
        height = int(width / 2.0)

    dst = np.array([
        [0, 0], [width-1, 0],
        [width-1, height-1], [0, height-1]
    ], dtype="float32")

    M = cv2.getPerspectiveTransform(pts, dst)
    warped = cv2.warpPerspective(img, M, (width, height))

    hsv = cv2.cvtColor(warped, cv2.COLOR_BGR2HSV)
    hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 30)
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])
    result = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)

    # Asegurar que el directorio existe
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    cv2.imwrite(output_path, result)

    # Valores predeterminados para otros parámetros
    microgrietas = 0
    fingers = 0
    black_edges = 0
    intensidad = 0

    # Asegurar que todos los valores son tipos básicos de Python (no numpy)
    result_dict = {
        "integridad": float(integridad),
        "luminosidad": float(luminosidad),
        "uniformidad": float(uniformidad),
        "filas": int(filas),
        "columnas": int(columnas),
        "microgrietas": int(microgrietas),
        "fingers": int(fingers),
        "black_edges": int(black_edges),
        "intensidad": int(intensidad)
    }

    # Asegurar salida UTF-8 válida
    json_output = json.dumps(result_dict, ensure_ascii=True)
    print(json_output)

if __name__ == "__main__":
    try:
        parser = argparse.ArgumentParser(description='Procesar imagen de panel solar')
        parser.add_argument('input_path', help='Ruta de la imagen de entrada')
        parser.add_argument('output_path', help='Ruta donde guardar la imagen procesada')
        parser.add_argument('--filas', type=int, default=10, help='Número de filas del panel (por defecto: 10)')
        parser.add_argument('--columnas', type=int, default=6, help='Número de columnas del panel (por defecto: 6)')

        args = parser.parse_args()
        process_image(args.input_path, args.output_path, args.filas, args.columnas)

    except Exception as e:
        # Asegurar que el error también sea UTF-8 válido
        error_msg = {"error": str(e)}
        print(json.dumps(error_msg, ensure_ascii=True))
        traceback.print_exc()
        sys.exit(1)
