export interface TextPart { text:string; href?:string }
/** Render text natively; only credential-free HTTP(S) links become anchors. */
export function noteParts(text:string):TextPart[]{
 const parts:TextPart[]=[];let offset=0
 for(const match of text.matchAll(/https?:\/\/[^\s<>"']+/gi)){
  const index=match.index!;if(index>offset)parts.push({text:text.slice(offset,index)})
  const candidate=match[0].replace(/[.,;!]+$/,'');let href:string|undefined
  try{const url=new URL(candidate);if(['http:','https:'].includes(url.protocol)&&!url.username&&!url.password)href=url.href}catch{}
  parts.push({text:candidate,...(href?{href}:{})});if(candidate.length<match[0].length)parts.push({text:match[0].slice(candidate.length)});offset=index+match[0].length
 }
 if(offset<text.length)parts.push({text:text.slice(offset)});return parts
}
