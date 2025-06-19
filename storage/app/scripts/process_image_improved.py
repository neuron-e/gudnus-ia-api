
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
    gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
    mean_val = np.mean(gray)
    std_val = np.std(gray)
    if mean_val < 5 and std_val < 3:
        return True, "Imagen totalmente negra o inutilizable"
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

def encontrar_contorno_valido(contornos, img_shape):
    height, width = img_shape[:2]
    area_total = height * width
    # Reducir umbral de 0.05 a 0.02 para capturar contornos en imágenes más difíciles
    contornos_validos = [
        cnt for cnt in contornos
        if cv2.contourArea(cnt) > 0.02 * area_total
    ]
    if not contornos_validos:
        return None
    return max(contornos_validos, key=cv2.contourArea)

def recorte_razonable(warped, original_shape):
    h, w = warped.shape[:2]
    H, W = original_shape[:2]

    if w < 100 or h < 100:
        return False  # Demasiado pequeño

    area_ratio = (h * w) / (H * W)
    if area_ratio < 0.2:
        return False  # Panel muy pequeño

    aspect_ratio = h / w
    if aspect_ratio < 0.8 or aspect_ratio > 2.5:
        return False  # Proporción rara (demasiado horizontal o vertical)

    return True

def process_image(input_path, output_path, filas=10, columnas=6):
    # Leer la imagen original
    img = cv2.imread(input_path)
    if img is None:
        raise Exception(f"No se pudo cargar la imagen: {input_path}")

    # 👉 ROTACIÓN AUTOMÁTICA si es horizontal
    h, w = img.shape[:2]
    if w > h:
        img = cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)

    # Verificar si la imagen es procesable
    es_inutilizable, mensaje = es_imagen_totalmente_inutilizable(img)
    if es_inutilizable:
        raise Exception(f"Imagen no procesable: {mensaje}")

    # Hacer una copia para la detección
    img_proc = img.copy()

    # ESTRATEGIA 1: Método original (para imágenes buenas)
    try:
        gray = cv2.cvtColor(img_proc, cv2.COLOR_BGR2GRAY)
        thresh = cv2.adaptiveThreshold(gray, 255, cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
                                      cv2.THRESH_BINARY, 11, 2)
        thresh = cv2.bitwise_not(thresh)
        thresh = cv2.morphologyEx(thresh, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))

        contours, _ = cv2.findContours(thresh, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

        # Usar el umbral original de 5%
        height, width = img.shape[:2]
        area_total = height * width
        contornos_validos = [
            cnt for cnt in contours
            if cv2.contourArea(cnt) > 0.05 * area_total
        ]

        if not contornos_validos:
            raise Exception("Método 1 falló: No se encontró un contorno válido del panel")

        panel_contour = max(contornos_validos, key=cv2.contourArea)

        epsilon = 0.02 * cv2.arcLength(panel_contour, True)
        approx = cv2.approxPolyDP(panel_contour, epsilon, True)

        if len(approx) > 4:
            box = cv2.boxPoints(cv2.minAreaRect(approx)).astype(np.int32)
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

        dst = np.array([[0, 0], [width-1, 0], [width-1, height-1], [0, height-1]], dtype="float32")
        M = cv2.getPerspectiveTransform(pts, dst)
        warped = cv2.warpPerspective(img, M, (width, height))

    # ESTRATEGIA 2: Método de umbralización global (para imágenes de contraste medio)
    except Exception as e:
        try:
            print(f"Método 1 falló: {str(e)}. Intentando método 2...", file=sys.stderr)

            # Mejorar contraste
            img_enhanced = cv2.convertScaleAbs(img, alpha=1.3, beta=15)
            gray = cv2.cvtColor(img_enhanced, cv2.COLOR_BGR2GRAY)

            # Umbralización de Otsu
            _, binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)

            # Operaciones morfológicas
            kernel = np.ones((5, 5), np.uint8)
            binary = cv2.morphologyEx(binary, cv2.MORPH_CLOSE, kernel)

            # Encontrar contornos
            contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

            # Filtrar por área (umbral reducido: 2%)
            height, width = img.shape[:2]
            area_total = height * width
            contornos_validos = [
                cnt for cnt in contours
                if cv2.contourArea(cnt) > 0.02 * area_total
            ]

            if not contornos_validos:
                raise Exception("No se encontró un contorno válido del panel")

            panel_contour = max(contornos_validos, key=cv2.contourArea)

            # Usar minAreaRect para una detección más precisa
            rect = cv2.minAreaRect(panel_contour)
            box = cv2.boxPoints(rect).astype(np.int32)
            pts = order_points(box.reshape(4, 2))

            # Calcular dimensiones
            width_a = np.sqrt(((pts[1][0] - pts[0][0]) ** 2) + ((pts[1][1] - pts[0][1]) ** 2))
            width_b = np.sqrt(((pts[2][0] - pts[3][0]) ** 2) + ((pts[2][1] - pts[3][1]) ** 2))
            width = max(int(width_a), int(width_b))

            height_a = np.sqrt(((pts[3][0] - pts[0][0]) ** 2) + ((pts[3][1] - pts[0][1]) ** 2))
            height_b = np.sqrt(((pts[2][0] - pts[1][0]) ** 2) + ((pts[2][1] - pts[1][1]) ** 2))
            height = max(int(height_a), int(height_b))

            # Ajustar proporción
            if width / height < 0.5:
                width = int(height * 0.5)
            elif width / height > 2.0:
                height = int(width / 2.0)

            # Transformación de perspectiva
            dst = np.array([[0, 0], [width-1, 0], [width-1, height-1], [0, height-1]], dtype="float32")
            M = cv2.getPerspectiveTransform(pts, dst)
            warped = cv2.warpPerspective(img, M, (width, height))

        # ESTRATEGIA 3: Método de recorte directo (último recurso)
        except Exception as e:
            print(f"Método 2 falló: {str(e)}. Intentando método 3...", file=sys.stderr)

            # Umbralización simple pero con umbral muy bajo
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            _, binary = cv2.threshold(gray, 15, 255, cv2.THRESH_BINARY)

            # Encontrar todos los píxeles blancos
            y_coords, x_coords = np.where(binary > 0)

            if len(y_coords) < 100:
                raise Exception("No se encontraron suficientes píxeles del panel")

            # Obtener rectángulo
            x_min, x_max = np.min(x_coords), np.max(x_coords)
            y_min, y_max = np.min(y_coords), np.max(y_coords)

            # Añadir margen
            margin = 5
            x_min = max(0, x_min - margin)
            y_min = max(0, y_min - margin)
            x_max = min(img.shape[1] - 1, x_max + margin)
            y_max = min(img.shape[0] - 1, y_max + margin)

            # Recortar directamente
            warped = img[y_min:y_max, x_min:x_max]


    # ESTRATEGIA 3.5: Ajuste por contorno si el recorte actual no es razonable
    try:
        if 'warped' not in locals() or not recorte_razonable(warped, img.shape):
            print("⚠️ Aplicando estrategia 3.5", file=sys.stderr)
            gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
            _, binary = cv2.threshold(gray, 30, 255, cv2.THRESH_BINARY)

            contours, _ = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
            if contours:
                cnt = max(contours, key=cv2.contourArea)
                x, y, w, h = cv2.boundingRect(cnt)

                if w > img.shape[1] * 0.3 and h > img.shape[0] * 0.3:
                    margin = 10
                    x_min = max(0, x - margin)
                    y_min = max(0, y - margin)
                    x_max = min(img.shape[1] - 1, x + w + margin)
                    y_max = min(img.shape[0] - 1, y + h + margin)
                    warped = img[y_min:y_max, x_min:x_max]
    except Exception as e:
        print(f"Estrategia 3.5 falló: {e}", file=sys.stderr)

    # Recorte adicional para eliminar márgenes negros, solo si tiene sentido
    try:
        if 'warped' in locals() and recorte_razonable(warped, img.shape):
            gray = cv2.cvtColor(warped, cv2.COLOR_BGR2GRAY)
            _, mask = cv2.threshold(gray, 15, 255, cv2.THRESH_BINARY)
            y_coords, x_coords = np.where(mask > 0)

            if len(x_coords) > 0 and len(y_coords) > 0:
                x_min, x_max = np.min(x_coords), np.max(x_coords)
                y_min, y_max = np.min(y_coords), np.max(y_coords)
                warped = warped[y_min:y_max+1, x_min:x_max+1]
    except Exception as e:
        print(f"⚠️ Post-recorte fino falló: {e}", file=sys.stderr)

    # Mejorar la imagen resultante
    hsv = cv2.cvtColor(warped, cv2.COLOR_BGR2HSV)
    hsv[:, :, 2] = cv2.add(hsv[:, :, 2], 30)  # Aumentar brillo
    clahe = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8))
    hsv[:, :, 2] = clahe.apply(hsv[:, :, 2])
    result = cv2.cvtColor(hsv, cv2.COLOR_HSV2BGR)

    # Guardar y devolver resultados
    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    cv2.imwrite(output_path, result)

    # Calcular métricas del panel ya recortado
    integridad = calcular_integridad(warped)
    luminosidad = calcular_luminosidad(warped)
    uniformidad = calcular_uniformidad(warped)

    result_dict = {
        "integridad": float(integridad),
        "luminosidad": float(luminosidad),
        "uniformidad": float(uniformidad),
        "filas": int(filas),
        "columnas": int(columnas),
        "microgrietas": 0,
        "fingers": 0,
        "black_edges": 0,
        "intensidad": 0
    }

    print(json.dumps(result_dict, ensure_ascii=True))

if __name__ == "__main__":
    try:
        parser = argparse.ArgumentParser(description='Procesar imagen de panel solar')
        parser.add_argument('input_path', help='Ruta de la imagen de entrada')
        parser.add_argument('output_path', help='Ruta donde guardar la imagen procesada')
        parser.add_argument('--filas', type=int, default=10)
        parser.add_argument('--columnas', type=int, default=6)
        args = parser.parse_args()
        process_image(args.input_path, args.output_path, args.filas, args.columnas)
    except Exception as e:
        print(json.dumps({"error": str(e)}, ensure_ascii=True))
        traceback.print_exc()
        sys.exit(1)
